<?php

namespace Rocketeers\Laravel\Agent;

/**
 * Uploads batches to `POST {api_url}/ingest` and acts on each response as the ingest contract says:
 * done, dropped, retried with backoff, split in halves, or paused. A pause is for token and endpoint
 * problems (401, 403, 404, 405), where retrying is pointless: the agent then tells its clients to post
 * errors directly, and keeps doing so after the pause until one batch has gone through again.
 */
class Uploader
{
    public const MAX_IN_FLIGHT = 2;

    public const PAUSE_SECONDS = 300;

    public const GIVE_UP_SECONDS = 7200;

    protected const PAUSE_STATUSES = [401, 403, 404, 405];

    /** @var Transport */
    protected $transport;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $token;

    /** @var string */
    protected $version;

    /** @var string */
    protected $server;

    /** @var Backoff */
    protected $backoff;

    /** @var Log */
    protected $log;

    /** @var array<string, Batch> */
    protected $inFlight = [];

    /** @var Batch[] */
    protected $retries = [];

    /** @var int */
    protected $failures = 0;

    /** @var float */
    protected $retryAt = 0.0;

    /** @var float|null */
    protected $pausedUntil;

    /** @var string|null */
    protected $probe;

    /** @var array<string, int> */
    protected $stats = ['batches' => 0, 'records' => 0, 'retries' => 0, 'dropped' => 0];

    public function __construct(Transport $transport, string $baseUrl, string $token, string $version, ?string $server = null, ?Backoff $backoff = null, ?Log $log = null)
    {
        $this->transport = $transport;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->version = $version;
        $this->server = substr((string) ($server ?? gethostname()), 0, 255);
        $this->backoff = $backoff ?? new Backoff;
        $this->log = $log ?? Log::silent();
    }

    /**
     * Collect finished uploads and start new ones: retries first, then whatever $next takes from the
     * spool. $next is told when a batch is wanted even though none is due yet.
     *
     * @param  callable(bool $force): ?Batch  $next
     */
    public function pump(float $now, callable $next): void
    {
        foreach ($this->transport->poll() as $result) {
            if (isset($this->inFlight[$result['id']])) {
                $batch = $this->inFlight[$result['id']];

                unset($this->inFlight[$result['id']]);

                $this->handle($batch, $result, $now);
            }
        }

        if ($this->pausedUntil !== null) {
            $this->probe($now, $next);

            return;
        }

        while (count($this->inFlight) < self::MAX_IN_FLIGHT && $now >= $this->retryAt) {
            $batch = array_shift($this->retries) ?? $next(false);

            if ($batch === null) {
                return;
            }

            $this->start($batch);
        }
    }

    public function paused(): bool
    {
        return $this->pausedUntil !== null;
    }

    public function inFlight(): int
    {
        return count($this->inFlight);
    }

    /**
     * Nothing in flight and nothing waiting for a retry.
     */
    public function idle(): bool
    {
        return $this->inFlight === [] && $this->retries === [];
    }

    /**
     * Records the uploader still holds, in flight or waiting for a retry.
     */
    public function holding(): int
    {
        $records = 0;

        foreach (array_merge(array_values($this->inFlight), $this->retries) as $batch) {
            $records += $batch->count();
        }

        return $records;
    }

    /**
     * Seconds until the uploader may start a batch again.
     */
    public function readyIn(float $now): float
    {
        if ($this->pausedUntil !== null) {
            return $this->probe !== null ? INF : max(0.0, $this->pausedUntil - $now);
        }

        return count($this->inFlight) >= self::MAX_IN_FLIGHT ? INF : max(0.0, $this->retryAt - $now);
    }

    /**
     * @return array<string, int>
     */
    public function pullStats(): array
    {
        $stats = $this->stats;

        $this->stats = array_fill_keys(array_keys($stats), 0);

        return $stats;
    }

    /**
     * Once a pause is over, one batch goes out alone; clients are only let back in when it gets through.
     *
     * @param  callable(bool $force): ?Batch  $next
     */
    protected function probe(float $now, callable $next): void
    {
        if ($now < $this->pausedUntil || $this->probe !== null) {
            return;
        }

        $batch = array_shift($this->retries) ?? $next(true);

        if ($batch === null) {
            $this->pausedUntil = null;

            return;
        }

        $this->probe = $batch->id;

        $this->start($batch);
    }

    protected function start(Batch $batch): void
    {
        $body = $batch->body();
        $gzip = function_exists('gzencode') ? gzencode($body) : false;

        $this->inFlight[$batch->id] = $batch;

        $this->transport->post($batch->id, $this->baseUrl.'/ingest', $this->headers($batch, $gzip !== false), $gzip !== false ? $gzip : $body);
    }

    /**
     * @return string[]
     */
    protected function headers(Batch $batch, bool $gzip): array
    {
        $headers = [
            'Authorization: Bearer '.$this->token,
            'Content-Type: application/x-ndjson',
        ];

        if ($gzip) {
            $headers[] = 'Content-Encoding: gzip';
        }

        $headers[] = 'X-Rocketeers-Batch: '.$batch->id;

        if ($this->server !== '') {
            $headers[] = 'X-Rocketeers-Server: '.$this->server;
        }

        $headers[] = 'X-Rocketeers-Agent: '.$this->version;
        $headers[] = 'Accept: application/json';
        $headers[] = 'Expect:';

        return $headers;
    }

    /**
     * @param  array{id: string, status: int, retry_after: string|null, error: string|null, body: string}  $result
     */
    protected function handle(Batch $batch, array $result, float $now): void
    {
        $status = $result['status'];

        if ($this->probe === $batch->id) {
            $this->probe = null;

            if (! in_array($status, self::PAUSE_STATUSES, true)) {
                $this->pausedUntil = null;
            }
        }

        if ($status >= 200 && $status < 300) {
            $this->delivered($batch);
        } elseif (in_array($status, self::PAUSE_STATUSES, true)) {
            $this->pause($batch, $status, $now);
        } elseif ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
            $this->retry($batch, $result, $now);
        } elseif ($status === 413) {
            $this->split($batch);
        } else {
            $this->drop($batch, sprintf('the API answered %d%s', $status, static::reason($result['body'])));
        }
    }

    protected function delivered(Batch $batch): void
    {
        $this->failures = 0;
        $this->retryAt = 0.0;
        $this->pausedUntil = null;
        $this->stats['batches']++;
        $this->stats['records'] += $batch->count();
    }

    protected function pause(Batch $batch, int $status, float $now): void
    {
        $this->pausedUntil = $now + self::PAUSE_SECONDS;

        if ($now - $batch->createdAt >= self::GIVE_UP_SECONDS) {
            $this->drop($batch, sprintf('the API answered %d, and its records have been waiting for %d hours', $status, self::GIVE_UP_SECONDS / 3600));
        } else {
            array_unshift($this->retries, $batch);
        }

        $this->log->warning('paused', sprintf(
            'The API answered %d to %s/ingest: %s Uploads are paused for %d minutes; clients send errors directly meanwhile.',
            $status,
            $this->baseUrl,
            $status === 401 || $status === 403
                ? 'check that ROCKETEERS_API_TOKEN is the token of this environment.'
                : 'check ROCKETEERS_API_URL.',
            self::PAUSE_SECONDS / 60
        ));
    }

    /**
     * @param  array{id: string, status: int, retry_after: string|null, error: string|null, body: string}  $result
     */
    protected function retry(Batch $batch, array $result, float $now): void
    {
        $problem = $result['status'] === 0 ? (string) $result['error'] : 'the API answered '.$result['status'];

        if ($now - $batch->createdAt >= self::GIVE_UP_SECONDS) {
            $this->drop($batch, $problem.', and its records have been waiting for '.(self::GIVE_UP_SECONDS / 3600).' hours');

            return;
        }

        $this->failures++;
        $this->retryAt = $now + ($delay = $this->backoff->delay($this->failures, Backoff::retryAfter($result['retry_after'])));
        $this->stats['retries']++;

        array_unshift($this->retries, $batch);

        $this->log->warning('retry', sprintf('Upload failed: %s. Retrying in %.1f seconds.', $problem, $delay));
    }

    protected function split(Batch $batch): void
    {
        if ($batch->count() < 2) {
            $this->drop($batch, 'the API found a single record too large');

            return;
        }

        foreach (array_reverse($batch->split()) as $half) {
            array_unshift($this->retries, $half);
        }
    }

    protected function drop(Batch $batch, string $why): void
    {
        $this->stats['dropped'] += $batch->count();

        $this->log->warning('dropped', sprintf(
            'Dropped a batch of %d records (%d errors): %s.',
            $batch->count(),
            $batch->errors(),
            $why
        ));
    }

    protected static function reason(string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : '';

        return $message === '' ? '' : ' ('.substr($message, 0, 200).')';
    }
}
