<?php

namespace Rocketeers\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Rocketeers\Rocketeers;

class TestRocketeersCommand extends Command
{
    protected $signature = 'rocketeers:test';

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

        $exception = new Exception('Rocketeers test error triggered by rocketeers:test at '.now()->toDateTimeString());

        try {
            $client->report([
                'environment' => $environment,
                'code' => 500,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'url' => config('app.url'),
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to send the test error to Rocketeers: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test error sent to Rocketeers. Check your dashboard to confirm it arrived.');

        return self::SUCCESS;
    }
}
