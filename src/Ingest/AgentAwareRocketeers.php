<?php

namespace Rocketeers\Laravel\Ingest;

use Exception;
use Illuminate\Contracts\Container\Container;
use Rocketeers\Rocketeers;
use Throwable;

/**
 * Reports through the local agent when one is running, and exactly like the plain client when none is:
 * the same request with the same payload and no report id. An error goes to the agent at once, in one
 * frame with the outgoing requests buffered so far, so a fatal error that ends the process loses neither.
 * When the agent does not take the frame, the same report, report id included, is posted directly, so
 * the server stores it once whichever way it arrives.
 */
class AgentAwareRocketeers extends Rocketeers
{
    public const AGENT = 'agent';

    public const DIRECT = 'direct';

    /** @var bool */
    protected static $bypassed = false;

    /** @var Container|null */
    protected $container;

    /** @var string|null */
    protected $lastTransport;

    public function __construct($token, ?Container $container = null)
    {
        parent::__construct($token);

        $this->container = $container;
    }

    /**
     * The agent reports its own errors through this client as well, and must never deliver to itself.
     */
    public static function bypass(bool $bypass = true): void
    {
        static::$bypassed = $bypass;
    }

    public static function bypassed(): bool
    {
        return static::$bypassed;
    }

    public function report(array $data)
    {
        $connection = $this->agent();

        if ($connection === null) {
            $this->lastTransport = self::DIRECT;

            return parent::report($data);
        }

        $data['report_id'] = Uuid::v4();

        try {
            $json = $this->encodeReport($data);
        } catch (Exception $e) {
            return false;
        }

        if (is_string($json) && ($reply = $this->deliver($connection, $json)) === Frame::OK) {
            $this->lastTransport = self::AGENT;

            return $reply;
        }

        $this->lastTransport = self::DIRECT;

        return $this->postReport($json);
    }

    /**
     * How the last report travelled: through the agent, or posted directly.
     */
    public function lastTransport(): ?string
    {
        return $this->lastTransport;
    }

    protected function deliver(AgentConnection $connection, string $json): ?string
    {
        $buffer = $this->resolve(RecordBuffer::class);
        $context = $this->resolve(ExecutionContext::class);

        $line = '{"t":"error","v":1,"trace_id":'.json_encode($context ? $context->traceId() : null).',"payload":'.$json.'}';

        return $connection->send(array_merge([$line], $buffer ? $buffer->pull() : []));
    }

    protected function agent(): ?AgentConnection
    {
        if (static::$bypassed || empty($this->token) || $this->container === null) {
            return null;
        }

        try {
            if (! $this->container->make('config')->get('rocketeers.ingest.enabled', true)) {
                return null;
            }

            $connection = $this->container->make(AgentConnection::class);
        } catch (Throwable $e) {
            return null;
        }

        return $connection->present() ? $connection : null;
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    protected function resolve(string $abstract)
    {
        try {
            return $this->container !== null ? $this->container->make($abstract) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
