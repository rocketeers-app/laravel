<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * Masks credentials that some APIs carry in the url path itself: Telegram's `bot{id}:{token}` segment,
 * webhook and signed-download tokens. Any long segment mixing letters and digits is masked, except a
 * UUID, which identifies a resource rather than granting access to it. The server applies the same rule
 * again, so a record that bypasses this class is masked all the same.
 */
class PathMask
{
    public const MASK = '*****';

    public const MIN_LENGTH = 16;

    public static function mask(string $path): string
    {
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            $segments[$index] = static::segment($segment);
        }

        return implode('/', $segments);
    }

    protected static function segment(string $segment): string
    {
        if (preg_match('/^bot\d+:/', $segment)) {
            return 'bot'.self::MASK;
        }

        if (Uuid::isValid($segment)) {
            return $segment;
        }

        if (strlen($segment) >= self::MIN_LENGTH && preg_match('/\d/', $segment) && preg_match('/[A-Za-z]/', $segment)) {
            return self::MASK;
        }

        return $segment;
    }
}
