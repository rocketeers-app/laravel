<?php

namespace Rocketeers\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Support\StackTrace;
use Rocketeers\Rocketeers;

class TestRocketeersCommand extends Command
{
    protected $signature = 'rocketeers:test
        {--agent : Hand the test error to a running Rocketeers agent instead, which uploads it later, so whether it arrived cannot be shown}
        {--direct : Post the test error straight to the Rocketeers API, which is the default}';

    protected $description = 'Send a test error to Rocketeers and show whether it arrived.';

    public function handle(Rocketeers $client): int
    {
        $this->info('Testing Rocketeers error reporting...');

        if (empty(config('rocketeers.api_token'))) {
            $this->error('No Rocketeers API token configured. Set ROCKETEERS_API_TOKEN in your .env file.');

            return self::FAILURE;
        }

        $environment = app()->environment();
        $environments = config('rocketeers.environments', []);

        if (! in_array($environment, $environments)) {
            $this->warn(sprintf(
                'Heads up: the current environment "%s" is not listed in rocketeers.environments (%s), '
                .'so real errors would not be reported here. This test will still send one so you can '
                .'verify the API token and connectivity.',
                $environment,
                implode(', ', $environments) ?: 'none'
            ));
        }

        $agentRunning = $this->reportAgent();
        $this->reportOutgoingRequests($agentRunning);

        $exception = new Exception('Rocketeers test error triggered by rocketeers:test at '.now()->toDateTimeString());
        $bypassed = AgentAwareRocketeers::bypassed();

        // Only a direct post gets an answer from the API; the agent uploads later, out of sight.
        if (! $this->option('agent')) {
            AgentAwareRocketeers::bypass(true);
        }

        try {
            $response = $client->report([
                'environment' => $environment,
                'code' => 500,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => StackTrace::frames($exception, base_path()),
                'url' => config('app.url'),
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to send the test error to Rocketeers: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            AgentAwareRocketeers::bypass($bypassed);
        }

        if ($client instanceof AgentAwareRocketeers && $client->lastTransport() === AgentAwareRocketeers::AGENT) {
            $this->info('Test error handed to the Rocketeers agent, which uploads it within seconds.');
            $this->line('Whether it arrived cannot be shown from here: check your dashboard, or run this command without --agent.');

            return self::SUCCESS;
        }

        return $this->reportResponse($client, $response) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Tells from the API's answer to the direct post whether the test error arrived, and if not, why.
     */
    protected function reportResponse(Rocketeers $client, $response): bool
    {
        $url = Rocketeers::baseUrl().'/errors';
        $status = $client->lastStatus();

        if ($status === null) {
            $this->error(sprintf(
                'The test error did not arrive: %s could not be reached (%s).',
                $url,
                $client->lastError() ?: 'no response'
            ));

            return false;
        }

        if ($status >= 200 && $status < 300) {
            $this->info(sprintf('Rocketeers received the test error (HTTP %d from %s). It is on your dashboard now.', $status, $url));

            return true;
        }

        $this->error(sprintf('The test error did not arrive: Rocketeers answered HTTP %d from %s.', $status, $url));

        if (($message = $this->messageFrom($response)) !== null) {
            $this->line('Rocketeers said: '.$message);
        }

        if (($hint = $this->hintFor($status)) !== null) {
            $this->line($hint);
        }

        return false;
    }

    protected function hintFor(int $status): ?string
    {
        return match (true) {
            $status === 401 => 'The API token is not recognised. Check that ROCKETEERS_API_TOKEN holds the token of this environment in Rocketeers.',
            $status === 403 => 'The API token may not report errors. Use the token of this environment in Rocketeers, not a personal API token.',
            $status === 404 => 'Nothing takes errors at this address. Check ROCKETEERS_API_URL, or leave it empty to use the default.',
            $status === 429 => 'Too many requests. Wait a minute and try again.',
            $status >= 500 => 'Something went wrong at Rocketeers. Try again in a moment.',
            default => null,
        };
    }

    /**
     * The API answers in JSON with a "message"; anything else, such as a proxy's HTML page, is left out.
     */
    protected function messageFrom($response): ?string
    {
        if (! is_string($response) || $response === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? null) : $decoded;

        return is_string($message) && trim($message) !== '' ? Str::limit(trim($message), 300) : null;
    }

    protected function reportAgent(): bool
    {
        if (! config('rocketeers.ingest.enabled', true)) {
            $this->line('Rocketeers agent: not used, ingest is disabled (ROCKETEERS_INGEST=false).');

            return false;
        }

        $connection = $this->laravel->make(AgentConnection::class);
        $endpoint = $connection->endpoint();

        if ($endpoint->tooLong()) {
            $this->warn(sprintf(
                'Rocketeers agent: the socket path %s is %d bytes long, more than the %d a unix socket allows. '
                .'Set ROCKETEERS_INGEST_URI to a shorter unix:// path, or to tcp://127.0.0.1:<port>.',
                $endpoint->path(),
                strlen((string) $endpoint->path()),
                AgentEndpoint::MAX_PATH_BYTES
            ));

            return false;
        }

        $version = $connection->ping();

        if ($version === null) {
            $this->line(sprintf('Rocketeers agent: not running at %s, so errors are sent directly. Start it with `php artisan rocketeers:agent`.', $endpoint->uri()));

            return false;
        }

        $this->line(sprintf('Rocketeers agent: running (%s) at %s.', $version !== '' ? $version : 'unknown version', $endpoint->uri()));

        return true;
    }

    protected function reportOutgoingRequests(bool $agentRunning): void
    {
        $reason = null;
        $environment = $this->laravel->environment();
        $sampleRate = (float) config('rocketeers.ingest.outgoing_requests.sample_rate', 1.0);

        if (! config('rocketeers.ingest.enabled', true)) {
            $reason = 'ingest is disabled (ROCKETEERS_INGEST=false)';
        } elseif (! config('rocketeers.ingest.outgoing_requests.enabled', true)) {
            $reason = 'they are switched off (ROCKETEERS_OUTGOING_REQUESTS=false)';
        } elseif (! in_array($environment, (array) config('rocketeers.environments', []))) {
            $reason = sprintf('the "%s" environment is not listed in rocketeers.environments', $environment);
        } elseif (! method_exists(Factory::class, 'globalMiddleware')) {
            $reason = 'this Laravel version cannot add middleware to every HTTP client request (Laravel 10.14 or newer is needed)';
        } elseif (! $agentRunning) {
            $reason = 'the Rocketeers agent is not running';
        } elseif ($sampleRate <= 0) {
            $reason = 'the sample rate is 0 (ROCKETEERS_OUTGOING_REQUESTS_SAMPLE_RATE)';
        }

        if ($reason !== null) {
            $this->line('Outgoing requests: not recorded, because '.$reason.'.');

            return;
        }

        $this->line(sprintf(
            'Outgoing requests: recorded for %s of requests, jobs and commands: method, host, masked path, status, duration and sizes, never headers, bodies or query strings.',
            round(min(1.0, $sampleRate) * 100, 2).'%'
        ));
    }
}
