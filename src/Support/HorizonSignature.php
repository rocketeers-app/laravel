<?php

namespace Rocketeers\Laravel\Support;

class HorizonSignature
{
    /**
     * The route the Rocketeers dashboard polls.
     */
    public const PATH = 'rocketeers/horizon/stats';

    /**
     * Namespaces the signed payload so a signature minted here can never be
     * replayed against a different signed endpoint added later.
     */
    public const PURPOSE = 'horizon-stats';

    /**
     * Sign an expiry timestamp with the shared secret.
     */
    public static function sign(int $expires, string $secret): string
    {
        return hash_hmac('sha256', self::PURPOSE.'|'.$expires, $secret);
    }

    /**
     * Verify a signature from the query string. Fails closed on a missing
     * secret, a malformed expiry, or an expiry in the past.
     */
    public static function verify(?string $signature, ?string $expires, ?string $secret): bool
    {
        if (empty($signature) || empty($expires) || empty($secret)) {
            return false;
        }

        if (! ctype_digit($expires) || (int) $expires < time()) {
            return false;
        }

        return hash_equals(self::sign((int) $expires, $secret), $signature);
    }
}
