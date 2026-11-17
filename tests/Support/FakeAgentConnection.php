<?php

namespace Rocketeers\Laravel\Tests\Support;

use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\Frame;

/** An agent that is always there and answers every frame with a set reply, keeping what it was sent. */
class FakeAgentConnection extends AgentConnection
{
    /** @var array<int, string[]> */
    public array $frames = [];

    public function __construct(public ?string $reply = Frame::OK, public bool $present = true, public ?string $version = '2.24.0')
    {
        parent::__construct(new AgentEndpoint('unix:///tmp/rocketeers-fake.sock'), str_repeat('a', 32));
    }

    public function present(): bool
    {
        return $this->present;
    }

    public function send(array $lines): ?string
    {
        $this->frames[] = $lines;

        return $this->reply;
    }

    public function ping(): ?string
    {
        return $this->present ? $this->version : null;
    }

    /** @return string[] */
    public function lines(): array
    {
        return array_merge([], ...$this->frames);
    }
}
