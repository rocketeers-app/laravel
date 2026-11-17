<?php

namespace Rocketeers\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Support\StackTrace;
use Rocketeers\Rocketeers;

class TestRocketeersCommand extends Command
{
    protected $signature = 'rocketeers:test
        {--direct : Send the test error straight to the Rocketeers API, even when the agent is running}';

    protected $description = 'Send a test error to Rocketeers to verify the error reporting integration.';

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

        $exception = new Exception('Rocketeers test error triggered by rocketeers:test at '.now()->toDateTimeString());
        $bypassed = AgentAwareRocketeers::bypassed();

        if ($this->option('direct')) {
            AgentAwareRocketeers::bypass(true);
        }

        try {
            $client->report([
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

        $this->info('Test error sent to Rocketeers. Check your dashboard to confirm it arrived.');

        if ($client instanceof AgentAwareRocketeers && $client->lastTransport() === AgentAwareRocketeers::AGENT) {
            $this->line('Delivered through the Rocketeers agent, which uploads it within seconds.');
        } else {
            $this->line(sprintf('Sent directly to %s/errors.', Rocketeers::baseUrl()));
        }

        $this->reportOutgoingRequests($agentRunning);

        return self::SUCCESS;
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
