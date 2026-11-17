<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * The shared secret on every frame. It is derived from the API token rather than being the token, so
 * the agent can tell its own app's processes apart from anything else that reaches its socket without
 * the token itself ever crossing it.
 */
class IngestKey
{
    public static function fromToken(string $token): string
    {
        return substr(hash_hmac('sha256', 'rocketeers-ingest', $token), 0, 32);
    }
}
