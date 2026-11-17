<?php

namespace Rocketeers\Laravel\Listeners;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Context;
use Rocketeers\Laravel\Concerns\ExtractsExceptionCode;
use Rocketeers\Laravel\Support\StackTrace;
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

        // This listener reports straight to the client, past the exception handler, so it has
        // to ask the handler itself: otherwise ShouldntReport and dontReport() mean nothing for
        // an exception thrown in a job.
        if (! app(ExceptionHandler::class)->shouldReport($event->exception)) {
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
                'trace' => StackTrace::frames($event->exception, base_path()),
                'url' => config('app.url'),
                'connection' => $event->job->getConnectionName(),
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName() ?? $event->job->getName(),
                'body' => $event->job->getRawBody(),
                'context' => class_exists(Context::class) ? (Context::all() ?: null) : null,
            ]);
        } catch (\Throwable $e) {
            // Silently fail to prevent error loops
        }
    }
}
