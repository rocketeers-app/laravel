<?php

namespace Rocketeers\Laravel\Tests\Support;

use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;

/** Keeps what would have been posted to /v1/errors instead of posting it, and answers as told. */
class PostCapturingRocketeers extends AgentAwareRocketeers
{
    /** @var string[] */
    public array $posted = [];

    public ?int $status = 200;

    public string|false $response = '"OK"';

    public ?string $error = null;

    public function postReport($json)
    {
        $this->posted[] = $json;
        $this->lastStatus = $this->status;
        $this->lastError = $this->error;

        return $this->response;
    }
}
