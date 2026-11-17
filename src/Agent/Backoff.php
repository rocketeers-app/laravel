<?php

namespace Rocketeers\Laravel\Agent;

/**
 * How long to wait before retrying a failed upload: exponential from 1 up to 60 seconds with full jitter,
 * so the agents of many servers that lost the API at the same moment do not all return at the same
 * moment either. A Retry-After from the server is never undercut.
 */
class Backoff
{
    public const BASE_SECONDS = 1.0;

    public const MAX_SECONDS = 60.0;

    /** @var callable(): float */
    protected $random;

    /**
     * @param  (callable(): float)|null  $random  Returns a number from 0 to 1.
     */
    public function __construct(?callable $random = null)
    {
        $this->random = $random ?? static function (): float {
            return mt_rand() / mt_getrandmax();
        };
    }

    /**
     * @param  int  $failures  Consecutive failures so far, the first being 1.
     */
    public function delay(int $failures, ?int $retryAfter = null): float
    {
        $ceiling = min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** min(max($failures, 1) - 1, 10)));
        $delay = $ceiling * min(1.0, max(0.0, (float) ($this->random)()));

        return $retryAfter !== null ? max($delay, (float) $retryAfter) : $delay;
    }

    /**
     * Only the delay-seconds form is honoured; an HTTP date falls back to the backoff.
     */
    public static function retryAfter(?string $header): ?int
    {
        $header = trim((string) $header);

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }
}
