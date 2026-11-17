<?php

namespace Rocketeers\Laravel\Agent;

/**
 * The agent's only source of time, so tests can move it forward instead of waiting.
 */
class Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
