<?php

namespace Rocketeers\Laravel\Tests\Support;

use Rocketeers\Laravel\Agent\Clock;

class FakeClock extends Clock
{
    public function __construct(public float $now = 1800000000.0) {}

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
