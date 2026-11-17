<?php

namespace Rocketeers\Laravel\Agent;

/**
 * The agent's output: plain lines for the daemon's log. A warning of a kind that was logged less than a
 * minute ago is held back and counted instead, so an outage does not flood the log.
 */
class Log
{
    public const THROTTLE_SECONDS = 60;

    /** @var callable(string, string): void */
    protected $write;

    /** @var Clock */
    protected $clock;

    /** @var array<string, float> */
    protected $loggedAt = [];

    /** @var array<string, int> */
    protected $held = [];

    /**
     * @param  callable(string $level, string $message): void  $write
     */
    public function __construct(callable $write, ?Clock $clock = null)
    {
        $this->write = $write;
        $this->clock = $clock ?? new Clock;
    }

    public static function silent(): self
    {
        return new static(static function () {
            //
        });
    }

    public function info(string $message): void
    {
        ($this->write)('info', $message);
    }

    public function error(string $message): void
    {
        ($this->write)('error', $message);
    }

    public function debug(string $message): void
    {
        ($this->write)('debug', $message);
    }

    public function warning(string $kind, string $message): void
    {
        $now = $this->clock->now();

        if (isset($this->loggedAt[$kind]) && $now - $this->loggedAt[$kind] < self::THROTTLE_SECONDS) {
            $this->held[$kind] = ($this->held[$kind] ?? 0) + 1;

            return;
        }

        $held = $this->held[$kind] ?? 0;

        unset($this->held[$kind]);
        $this->loggedAt[$kind] = $now;

        ($this->write)('warning', $held > 0 ? sprintf('%s (%d more like this in the last minute)', $message, $held) : $message);
    }
}
