<?php

namespace Rocketeers\Laravel;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\ServiceProvider;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\ExecutionContext;
use Rocketeers\Laravel\Ingest\IngestKey;
use Rocketeers\Laravel\Ingest\RecordBuffer;
use Rocketeers\Laravel\Ingest\RecordOutgoingRequests;
use Throwable;

/**
 * Wires the app to the local agent. Nothing is hooked in unless a token is set, ingest is enabled and the
 * environment is one that reports; even then every hook does nothing until an agent is actually present,
 * so an app that never runs one behaves exactly as before. Every framework API newer than the oldest
 * supported Laravel is looked up by name first, so booting never depends on one.
 */
class RocketeersIngestServiceProvider extends ServiceProvider
{
    public const AGENT_COMMAND = 'rocketeers:agent';

    /** @var bool */
    protected static $flushesOnShutdown = false;

    /** @var array<int, array{trace_id: string, type: string, name: string|null, sampled: bool}> */
    protected $commands = [];

    public function register()
    {
        static::registerMacros();

        $this->app->singleton(AgentEndpoint::class, function () {
            return AgentEndpoint::fromConfig(config('rocketeers.ingest.uri'), storage_path('framework'));
        });

        $this->app->singleton(AgentConnection::class, function ($app) {
            return new AgentConnection($app->make(AgentEndpoint::class), IngestKey::fromToken((string) config('rocketeers.api_token')));
        });

        $this->app->singleton(ExecutionContext::class, function ($app) {
            return new ExecutionContext(
                $app->runningInConsole() ? ExecutionContext::COMMAND : ExecutionContext::REQUEST,
                (float) config('rocketeers.ingest.outgoing_requests.sample_rate', 1.0),
                function () {
                    return static::routeName();
                }
            );
        });

        $this->app->singleton(RecordBuffer::class, function ($app) {
            return new RecordBuffer($app->make(AgentConnection::class));
        });

        $this->app->singleton(RecordOutgoingRequests::class, function ($app) {
            return new RecordOutgoingRequests(
                $app->make(AgentConnection::class),
                $app->make(RecordBuffer::class),
                $app->make(ExecutionContext::class)
            );
        });
    }

    public function boot()
    {
        if (! static::active()) {
            return;
        }

        $this->shareAcrossRequests();
        $this->flushWhenExecutionsEnd();
        $this->followExecutions();
        $this->recordOutgoingRequests();
    }

    public static function registerMacros(): void
    {
        if (! class_exists(PendingRequest::class) || ! method_exists(PendingRequest::class, 'macro')) {
            return;
        }

        PendingRequest::macro('withoutRecording', function (): PendingRequest {
            return $this->withOptions([RecordOutgoingRequests::OPTION => false]);
        });
    }

    public static function active(): bool
    {
        return ! empty(config('rocketeers.api_token'))
            && (bool) config('rocketeers.ingest.enabled', true)
            && in_array(app()->environment(), (array) config('rocketeers.environments', []));
    }

    /**
     * Octane serves each request from a clone of the booted app, so an object first resolved during a
     * request would live in that clone alone, out of reach of the hooks registered here. Resolving them
     * at boot puts one buffer and one context in the app itself, which every clone shares.
     */
    protected function shareAcrossRequests(): void
    {
        foreach ([AgentEndpoint::class, AgentConnection::class, ExecutionContext::class, RecordBuffer::class] as $abstract) {
            $this->app->make($abstract);
        }
    }

    protected function flushWhenExecutionsEnd(): void
    {
        $this->app->terminating(function () {
            $this->flush();
        });

        $this->app['events']->listen('Illuminate\Queue\Events\Looping', function () {
            $this->flush();
        });

        $this->app['events']->listen('Illuminate\Queue\Events\WorkerStopping', function () {
            $this->flush();
        });

        if (! static::$flushesOnShutdown) {
            static::$flushesOnShutdown = true;

            register_shutdown_function(static function () {
                $app = Container::getInstance();

                if ($app->resolved(RecordBuffer::class)) {
                    try {
                        $app->make(RecordBuffer::class)->flush();
                    } catch (Throwable $e) {
                        return;
                    }
                }
            });
        }
    }

    /**
     * A job run by the sync driver is part of the request or command that dispatched it, so it keeps
     * that execution's trace. A command started from within another one gives the trace back when done.
     */
    protected function followExecutions(): void
    {
        $events = $this->app['events'];

        $events->listen('Illuminate\Console\Events\CommandStarting', function ($event) {
            $command = isset($event->command) ? (string) $event->command : null;

            $this->commands[] = $this->context()->snapshot();

            if ($command === self::AGENT_COMMAND) {
                AgentAwareRocketeers::bypass(true);

                $this->context()->start(ExecutionContext::COMMAND, $command, 0.0);

                return;
            }

            $this->context()->start(ExecutionContext::COMMAND, $command);
        });

        $events->listen('Illuminate\Console\Events\CommandFinished', function () {
            if ($this->commands !== []) {
                $this->context()->restore(array_pop($this->commands));
            }
        });

        $events->listen('Illuminate\Queue\Events\JobProcessing', function ($event) {
            if (($event->connectionName ?? null) === 'sync') {
                return;
            }

            $job = $event->job;

            $this->context()->start(ExecutionContext::JOB, method_exists($job, 'resolveName') ? $job->resolveName() : $job->getName());
        });

        $events->listen('Laravel\Octane\Events\RequestReceived', function () {
            $this->context()->start(ExecutionContext::REQUEST);
        });

        if (! $this->app->runningInConsole()) {
            $this->context()->start(ExecutionContext::REQUEST);
        }
    }

    /**
     * Global middleware on the HTTP client arrived in Laravel 10.14.
     */
    protected function recordOutgoingRequests(): void
    {
        if (! config('rocketeers.ingest.outgoing_requests.enabled', true) || ! method_exists(Factory::class, 'globalMiddleware')) {
            return;
        }

        $register = function ($factory) {
            $factory->globalMiddleware($this->app->make(RecordOutgoingRequests::class));
        };

        $this->app->afterResolving(Factory::class, $register);

        if ($this->app->resolved(Factory::class)) {
            $register($this->app->make(Factory::class));
        }
    }

    protected function flush(): void
    {
        if (! $this->app->resolved(RecordBuffer::class)) {
            return;
        }

        try {
            $this->app->make(RecordBuffer::class)->flush();
        } catch (Throwable $e) {
            return;
        }
    }

    protected function context(): ExecutionContext
    {
        return $this->app->make(ExecutionContext::class);
    }

    /**
     * Routes cached by `route:cache` get a random `generated::` name, which says nothing; their uri does.
     */
    protected static function routeName(): ?string
    {
        $app = Container::getInstance();

        if (! $app->bound('request')) {
            return null;
        }

        $route = $app->make('request')->route();

        if (! is_object($route)) {
            return null;
        }

        $name = method_exists($route, 'getName') ? $route->getName() : null;

        if (is_string($name) && $name !== '' && strpos($name, 'generated::') !== 0) {
            return $name;
        }

        return method_exists($route, 'uri') ? $route->uri() : null;
    }
}
