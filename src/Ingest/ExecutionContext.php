<?php

namespace Rocketeers\Laravel\Ingest;

use Throwable;

/**
 * Ties together what one request, job or command does under one trace id. Whether its outgoing requests
 * are recorded is decided once, when it starts, so a sampled-out execution records none of them rather
 * than a random subset. A request is named after its route, which only exists once the router has run,
 * so that name is looked up when it is first needed.
 */
class ExecutionContext
{
    public const REQUEST = 'request';

    public const JOB = 'job';

    public const COMMAND = 'command';

    public const MAX_NAME_LENGTH = 255;

    /** @var string */
    protected $traceId;

    /** @var string */
    protected $type;

    /** @var string|null */
    protected $name;

    /** @var bool */
    protected $sampled;

    /** @var float */
    protected $sampleRate;

    /** @var (callable(): ?string)|null */
    protected $requestName;

    public function __construct(string $type = self::REQUEST, float $sampleRate = 1.0, ?callable $requestName = null)
    {
        $this->sampleRate = $sampleRate;
        $this->requestName = $requestName;

        $this->start($type);
    }

    public function start(string $type, ?string $name = null, ?float $sampleRate = null): void
    {
        $this->traceId = Uuid::v4();
        $this->type = $type;
        $this->name = static::limit($name);
        $this->sampled = static::roll($sampleRate ?? $this->sampleRate);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): ?string
    {
        if ($this->name === null && $this->type === self::REQUEST && $this->requestName !== null) {
            try {
                $this->name = static::limit(($this->requestName)());
            } catch (Throwable $e) {
                return null;
            }
        }

        return $this->name;
    }

    public function sampled(): bool
    {
        return $this->sampled;
    }

    /**
     * @return array{trace_id: string, type: string, name: string|null, sampled: bool}
     */
    public function snapshot(): array
    {
        return [
            'trace_id' => $this->traceId,
            'type' => $this->type,
            'name' => $this->name,
            'sampled' => $this->sampled,
        ];
    }

    /**
     * @param  array{trace_id: string, type: string, name: string|null, sampled: bool}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->traceId = $snapshot['trace_id'];
        $this->type = $snapshot['type'];
        $this->name = $snapshot['name'];
        $this->sampled = $snapshot['sampled'];
    }

    protected static function roll(float $rate): bool
    {
        if ($rate >= 1) {
            return true;
        }

        return $rate > 0 && mt_rand() / mt_getrandmax() < $rate;
    }

    protected static function limit(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
