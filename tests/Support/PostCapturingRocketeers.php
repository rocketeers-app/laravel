<?php

namespace Rocketeers\Laravel\Tests\Support;

use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;

/** Keeps what would have been posted to /v1/errors instead of posting it. */
class PostCapturingRocketeers extends AgentAwareRocketeers
{
    /** @var string[] */
    public array $posted = [];

    public function postReport($json)
    {
        $this->posted[] = $json;

        return '"OK"';
    }
}
