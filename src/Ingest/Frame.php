<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * The local wire format between app processes and the agent: one frame per connection,
 * `{len}:v1:{key}:{body}`, where `len` counts the bytes after its own colon and the body is NDJSON
 * records or `PING`. The agent answers with one line and closes. The length prefix lets the agent
 * read a frame that arrives in pieces without scanning it, and refuse an oversized one unread.
 */
class Frame
{
    public const VERSION = 'v1';

    /** Everything after the length prefix. */
    public const MAX_BYTES = 4194304;

    public const PING = 'PING';

    public const OK = 'OK';

    public const PONG = 'PONG';

    public const ERR_KEY = 'ERR key';

    public const ERR_VERSION = 'ERR version';

    public const ERR_SIZE = 'ERR size';

    public const ERR_FULL = 'ERR full';

    public const ERR_PAUSED = 'ERR paused';

    public const ERR_MALFORMED = 'ERR malformed';

    public const INCOMPLETE = 'incomplete';

    public const COMPLETE = 'complete';

    public const REJECTED = 'rejected';

    protected const MAX_PREFIX_DIGITS = 7;

    /** Room for `v1:{key}:`, with plenty to spare for a later version. */
    protected const MAX_HEADER_BYTES = 64;

    /**
     * Returns null when the frame would exceed MAX_BYTES, which no agent accepts.
     */
    public static function encode(string $key, string $body): ?string
    {
        $payload = self::VERSION.':'.$key.':'.$body;

        if (strlen($payload) > self::MAX_BYTES) {
            return null;
        }

        return strlen($payload).':'.$payload;
    }

    /**
     * Parse what has arrived of a frame so far: [INCOMPLETE, null] until all of it is there, then
     * [COMPLETE, body], or [REJECTED, reply] as soon as it can no longer be valid. The version and key
     * are checked as soon as they have arrived, so a peer without the key never gets the agent to hold
     * more than a few bytes for it.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function parse(string $buffer, string $key): array
    {
        $colon = strpos($buffer, ':');
        $prefix = $colon === false ? $buffer : substr($buffer, 0, $colon);

        if ($prefix !== '' && ! ctype_digit($prefix)) {
            return [self::REJECTED, self::ERR_MALFORMED];
        }

        if (strlen($prefix) > self::MAX_PREFIX_DIGITS || (int) $prefix > self::MAX_BYTES) {
            return [self::REJECTED, self::ERR_SIZE];
        }

        if ($colon === false) {
            return [self::INCOMPLETE, null];
        }

        $length = (int) $prefix;

        if ($length === 0) {
            return [self::REJECTED, self::ERR_MALFORMED];
        }

        $received = min($length, strlen($buffer) - $colon - 1);
        $complete = $received === $length;
        $parts = explode(':', substr($buffer, $colon + 1, min($received, self::MAX_HEADER_BYTES)), 3);

        if (count($parts) === 1 && ! $complete) {
            return $received < self::MAX_HEADER_BYTES ? [self::INCOMPLETE, null] : [self::REJECTED, self::ERR_MALFORMED];
        }

        if ($parts[0] !== self::VERSION) {
            return [self::REJECTED, preg_match('/^v\d+$/', $parts[0]) ? self::ERR_VERSION : self::ERR_MALFORMED];
        }

        if (count($parts) === 2) {
            return $complete || $received >= self::MAX_HEADER_BYTES ? [self::REJECTED, self::ERR_MALFORMED] : [self::INCOMPLETE, null];
        }

        if (! hash_equals($key, $parts[1])) {
            return [self::REJECTED, self::ERR_KEY];
        }

        if (! $complete) {
            return [self::INCOMPLETE, null];
        }

        $header = strlen($parts[0]) + strlen($parts[1]) + 2;

        return [self::COMPLETE, (string) substr($buffer, $colon + 1 + $header, $length - $header)];
    }
}
