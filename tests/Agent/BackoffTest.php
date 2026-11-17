<?php

namespace Rocketeers\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Agent\Backoff;

class BackoffTest extends TestCase
{
    public function test_the_ceiling_doubles_from_one_second_up_to_a_minute()
    {
        $backoff = new Backoff(function () {
            return 1.0;
        });

        $this->assertSame(
            [1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 60.0, 60.0],
            array_map([$backoff, 'delay'], [1, 2, 3, 4, 5, 6, 7, 50])
        );
    }

    public function test_the_delay_is_a_random_share_of_the_ceiling()
    {
        $this->assertSame(0.0, (new Backoff(function () {
            return 0.0;
        }))->delay(5));

        $this->assertSame(8.0, (new Backoff(function () {
            return 0.5;
        }))->delay(5));

        $backoff = new Backoff;

        for ($i = 0; $i < 100; $i++) {
            $delay = $backoff->delay(3);

            $this->assertGreaterThanOrEqual(0.0, $delay);
            $this->assertLessThanOrEqual(4.0, $delay);
        }
    }

    public function test_retry_after_is_never_undercut()
    {
        $backoff = new Backoff(function () {
            return 1.0;
        });

        $this->assertSame(30.0, $backoff->delay(1, 30));
        $this->assertSame(60.0, $backoff->delay(10, 5));
    }

    public function test_only_retry_after_seconds_are_understood()
    {
        $this->assertSame(120, Backoff::retryAfter('120'));
        $this->assertSame(0, Backoff::retryAfter(' 0 '));
        $this->assertNull(Backoff::retryAfter('Wed, 21 Oct 2026 07:28:00 GMT'));
        $this->assertNull(Backoff::retryAfter(''));
        $this->assertNull(Backoff::retryAfter(null));
    }
}
