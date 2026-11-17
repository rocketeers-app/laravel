<?php

namespace Rocketeers\Laravel\Agent;

/**
 * Sends the uploader's requests without blocking the agent's loop: a post is started, and its outcome
 * collected by a later poll().
 */
interface Transport
{
    /**
     * @param  string[]  $headers
     */
    public function post(string $id, string $url, array $headers, string $body): void;

    /**
     * The posts that finished since the last poll. A status of 0 means no response arrived; the error
     * says why.
     *
     * @return array<int, array{id: string, status: int, retry_after: string|null, error: string|null, body: string}>
     */
    public function poll(): array;
}
