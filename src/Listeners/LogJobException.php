<?php

namespace Rocketeers\Laravel\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Rocketeers\Laravel\Concerns\ExtractsExceptionCode;
use Rocketeers\Rocketeers;

class LogJobException
{
    use ExtractsExceptionCode;

    protected $client;

    public function __construct(Rocketeers $client)
    {
        $this->client = $client;
    }

    public function handle(JobExceptionOccurred $event)
    {
        if (! in_array(app()->environment(), config('rocketeers.environments'))) {
            return;
        }

        try {
            $this->client->report([
                'environment' => app()->environment(),
                'code' => $this->getCodeFromException($event->exception) ?: 500,
                'exception' => method_exists($event->exception, 'getOriginalClassName') ? $event->exception->getOriginalClassName() : get_class($event->exception),
                'message' => $event->exception->getMessage(),
                'file' => $event->exception->getFile(),
                'line' => $event->exception->getLine(),
                'trace' => $event->exception->getTrace(),
                'url' => config('app.url'),
                'connection' => $event->job->getConnectionName(),
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName() ?? $event->job->getName(),
                'body' => $event->job->getRawBody(),
            ]);
        } catch (\Throwable $e) {
            // Silently fail to prevent error loops
        }
    }
}
