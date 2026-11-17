<?php

namespace Rocketeers\Laravel\Agent;

use Composer\InstalledVersions;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\Frame;
use RuntimeException;
use Throwable;

/**
 * The daemon behind `rocketeers:agent`: one stream_select loop that takes frames from app processes on a
 * socket, spools their records and has the uploader send them on. It is plain PHP on purpose, so it runs
 * wherever the app does, and every tick is guarded, so a bad frame or a failed upload never stops it.
 * stop() only raises a flag, which makes it safe to call from a signal handler.
 */
class Agent
{
    public const MAX_CONNECTIONS = 256;

    public const CONNECTION_SECONDS = 5.0;

    public const BUSY_WAIT_SECONDS = 0.01;

    public const IDLE_WAIT_SECONDS = 1.0;

    public const STATS_SECONDS = 60;

    /** @var AgentEndpoint */
    protected $endpoint;

    /** @var string */
    protected $key;

    /** @var Uploader */
    protected $uploader;

    /** @var Log */
    protected $log;

    /** @var string */
    protected $version;

    /** @var float */
    protected $drainSeconds;

    /** @var bool */
    protected $reportsStats;

    /** @var Clock */
    protected $clock;

    /** @var Spool */
    protected $spool;

    /** @var resource|null */
    protected $server;

    /** @var string|null */
    protected $socketPath;

    /** @var array<int, ClientConnection> */
    protected $connections = [];

    /** @var bool */
    protected $stopRequested = false;

    /** @var bool */
    protected $stopping = false;

    /** @var float|null */
    protected $drainUntil;

    /** @var bool */
    protected $reportedKeyMismatch = false;

    /** @var float */
    protected $statsSince;

    /** @var array<string, int> */
    protected $stats = ['frames' => 0, 'errors' => 0, 'outgoing' => 0, 'refused' => 0];

    /**
     * @param  array{version?: string, drain?: float|int, stats?: bool, clock?: Clock, spool?: Spool}  $options
     */
    public function __construct(string $address, string $key, Uploader $uploader, Log $log, array $options = [])
    {
        $this->endpoint = new AgentEndpoint($address);
        $this->key = $key;
        $this->uploader = $uploader;
        $this->log = $log;
        $this->version = (string) ($options['version'] ?? static::packageVersion());
        $this->drainSeconds = max(0.0, (float) ($options['drain'] ?? 5.0));
        $this->reportsStats = (bool) ($options['stats'] ?? false);
        $this->clock = $options['clock'] ?? new Clock;
        $this->spool = $options['spool'] ?? new Spool;
        $this->statsSince = $this->clock->now();
    }

    public static function packageVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('rocketeers-app/laravel')) {
            return InstalledVersions::getPrettyVersion('rocketeers-app/laravel') ?: 'dev';
        }

        return 'dev';
    }

    public function address(): string
    {
        return $this->endpoint->listenAddress();
    }

    public function version(): string
    {
        return $this->version;
    }

    public function spool(): Spool
    {
        return $this->spool;
    }

    /**
     * Bind the socket. A unix socket left behind by an agent that died is replaced, which is only safe
     * because the caller holds the agent lock. The socket is created group-writable (0660), so the web
     * server's user can deliver to it and other users cannot.
     *
     * @throws RuntimeException
     */
    public function listen(): void
    {
        $uri = $this->endpoint->listenAddress();
        $error = '';

        if ($this->endpoint->isUnix()) {
            $path = (string) $this->endpoint->path();

            if ($this->endpoint->tooLong()) {
                throw new RuntimeException(sprintf(
                    'The socket path %s is %d bytes long, more than the %d a unix socket allows. Set ROCKETEERS_INGEST_URI to a shorter unix:// path, or to tcp://127.0.0.1:<port>.',
                    $path,
                    strlen($path),
                    AgentEndpoint::MAX_PATH_BYTES
                ));
            }

            if (@filetype($path) === 'socket') {
                @unlink($path);
            }

            $umask = umask(0117);

            try {
                $server = @stream_socket_server($uri, $errno, $error);
            } finally {
                umask($umask);
            }

            if (is_resource($server)) {
                @chmod($path, 0660);

                $this->socketPath = $path;
            }
        } else {
            $server = @stream_socket_server($uri, $errno, $error);
        }

        if (! is_resource($server)) {
            throw new RuntimeException(sprintf('Could not listen on %s: %s', $uri, $error ?: 'unknown error'));
        }

        stream_set_blocking($server, false);

        $this->server = $server;
    }

    /**
     * Safe to call from a signal handler: the loop stops accepting at its next tick.
     */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    public function stopping(): bool
    {
        return $this->stopRequested || $this->stopping;
    }

    /**
     * Run until stopped and drained.
     *
     * @param  (callable(Agent): void)|null  $afterTick
     */
    public function run(?callable $afterTick = null): void
    {
        while (! $this->finished()) {
            $this->tick();

            if ($afterTick !== null) {
                $afterTick($this);
            }
        }

        $this->shutdown();
    }

    /**
     * One pass of the loop: wait for sockets (or the next due upload), take frames, upload.
     */
    public function tick(?float $timeout = null): void
    {
        try {
            $this->beginStoppingWhenAsked();
            $this->wait(max(0.0, $timeout ?? $this->timeout()));
            $this->beginStoppingWhenAsked();

            $now = $this->clock->now();

            $this->expireConnections($now);

            $this->uploader->pump($now, function (bool $force) use ($now) {
                return $force || $this->stopping || $this->spool->due($now) ? $this->spool->take() : null;
            });

            $this->report($now);
        } catch (Throwable $e) {
            $this->log->warning('tick', 'Unexpected error: '.$e->getMessage());
        }
    }

    /**
     * Stopped, and either everything is uploaded, nothing more can be, or the drain time is up.
     */
    public function finished(): bool
    {
        if (! $this->stopping) {
            return false;
        }

        if ($this->clock->now() >= $this->drainUntil) {
            return true;
        }

        if ($this->connections !== [] || $this->uploader->inFlight() > 0) {
            return false;
        }

        return $this->uploader->paused() || ($this->spool->isEmpty() && $this->uploader->idle());
    }

    protected function timeout(): float
    {
        if ($this->stopping || $this->uploader->inFlight() > 0) {
            return self::BUSY_WAIT_SECONDS;
        }

        $now = $this->clock->now();
        $timeout = self::IDLE_WAIT_SECONDS;

        if (! $this->spool->isEmpty()) {
            $timeout = min($timeout, max($this->spool->dueIn($now), $this->uploader->readyIn($now)));
        }

        if (! $this->uploader->idle()) {
            $timeout = min($timeout, $this->uploader->readyIn($now));
        }

        return $timeout;
    }

    protected function wait(float $timeout): void
    {
        $read = [];

        if (is_resource($this->server) && count($this->connections) < self::MAX_CONNECTIONS) {
            $read[] = $this->server;
        }

        foreach ($this->connections as $connection) {
            $read[] = $connection->socket();
        }

        if ($read === []) {
            usleep((int) round($timeout * 1000000));

            return;
        }

        $write = null;
        $except = null;
        $seconds = (int) floor($timeout);

        if (! @stream_select($read, $write, $except, $seconds, (int) round(($timeout - $seconds) * 1000000))) {
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->server) {
                $this->accept();
            } elseif (isset($this->connections[(int) $socket])) {
                $this->receive($this->connections[(int) $socket]);
            }
        }
    }

    protected function accept(): void
    {
        while (is_resource($this->server) && count($this->connections) < self::MAX_CONNECTIONS) {
            $socket = @stream_socket_accept($this->server, 0);

            if (! is_resource($socket)) {
                return;
            }

            $connection = new ClientConnection($socket, $this->clock->now() + self::CONNECTION_SECONDS);

            $this->connections[(int) $socket] = $connection;

            $this->receive($connection);
        }
    }

    protected function receive(ClientConnection $connection): void
    {
        if (! $connection->read()) {
            $this->close($connection);

            return;
        }

        [$state, $value] = $connection->frame($this->key);

        if ($state === Frame::INCOMPLETE) {
            return;
        }

        $reply = $state === Frame::COMPLETE ? $this->take((string) $value) : (string) $value;

        if ($reply !== Frame::OK && strncmp($reply, Frame::PONG, strlen(Frame::PONG)) !== 0) {
            $this->stats['refused']++;
        }

        if ($reply === Frame::ERR_KEY && ! $this->reportedKeyMismatch) {
            $this->reportedKeyMismatch = true;

            $this->log->warning('key', 'Refused a frame signed with another key. Restart the agent after rotating ROCKETEERS_API_TOKEN, so it uses the same token as the app.');
        }

        $this->close($connection, $reply);
    }

    protected function take(string $body): string
    {
        if ($body === Frame::PING) {
            return Frame::PONG.' '.$this->version;
        }

        if ($this->uploader->paused()) {
            return Frame::ERR_PAUSED;
        }

        [$errors, $others] = Spool::classify($body);

        if ($errors === [] && $others === []) {
            return Frame::ERR_MALFORMED;
        }

        if (! $this->spool->push($errors, $others, $this->clock->now())) {
            $this->log->warning('full', 'The error queue is full; clients send errors directly until uploads catch up.');

            return Frame::ERR_FULL;
        }

        $this->stats['frames']++;
        $this->stats['errors'] += count($errors);
        $this->stats['outgoing'] += count($others);

        return Frame::OK;
    }

    protected function close(ClientConnection $connection, ?string $reply = null): void
    {
        unset($this->connections[(int) $connection->socket()]);

        if ($reply === null) {
            $connection->close();
        } else {
            $connection->reply($reply);
        }
    }

    protected function expireConnections(float $now): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->expired($now)) {
                $this->close($connection);
            }
        }
    }

    /**
     * Clients only look for the socket file, so removing it first sends them straight to their fallback
     * while what was already accepted is uploaded.
     */
    protected function beginStoppingWhenAsked(): void
    {
        if (! $this->stopRequested || $this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->drainUntil = $this->clock->now() + $this->drainSeconds;

        if ($this->socketPath !== null) {
            @unlink($this->socketPath);
        }

        if (is_resource($this->server)) {
            @fclose($this->server);
        }

        $this->server = null;

        $this->log->info(sprintf('Stopping: no longer accepting records, uploading what is left for up to %s seconds.', $this->drainSeconds));
    }

    protected function shutdown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];

        $left = $this->spool->count() + $this->uploader->holding();

        if ($left > 0) {
            $this->log->warning('shutdown', sprintf('Stopped with %d records not uploaded; they are lost.', $left));
        } else {
            $this->log->info('Stopped.');
        }
    }

    protected function report(float $now): void
    {
        if (($dropped = $this->spool->pullDropped()) > 0) {
            $this->log->warning('overflow', sprintf('Dropped the %d oldest outgoing requests to stay within %d MiB.', $dropped, Spool::MAX_OUTGOING_BYTES / 1048576));
        }

        if (! $this->reportsStats || $now - $this->statsSince < self::STATS_SECONDS) {
            return;
        }

        $uploads = $this->uploader->pullStats();

        $this->log->debug(sprintf(
            'Last %d seconds: %d frames with %d errors and %d outgoing requests, %d refused; %d batches with %d records uploaded, %d retries, %d records dropped; %d records (%d KiB) waiting.',
            (int) round($now - $this->statsSince),
            $this->stats['frames'],
            $this->stats['errors'],
            $this->stats['outgoing'],
            $this->stats['refused'],
            $uploads['batches'],
            $uploads['records'],
            $uploads['retries'],
            $uploads['dropped'],
            $this->spool->count() + $this->uploader->holding(),
            (int) ceil($this->spool->size() / 1024)
        ));

        $this->stats = array_fill_keys(array_keys($this->stats), 0);
        $this->statsSince = $now;
    }
}
