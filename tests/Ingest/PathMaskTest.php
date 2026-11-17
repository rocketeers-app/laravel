<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Ingest\PathMask;

class PathMaskTest extends TestCase
{
    public function test_a_telegram_bot_token_is_masked()
    {
        $this->assertSame('/bot*****/sendMessage', PathMask::mask('/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage'));
    }

    public function test_a_uuid_is_kept()
    {
        $this->assertSame('/v1/servers/0b7e6f1e-8c4d-4a3b-9f2e-1d2c3b4a5e6f', PathMask::mask('/v1/servers/0b7e6f1e-8c4d-4a3b-9f2e-1d2c3b4a5e6f'));
        $this->assertSame('/v1/servers/0B7E6F1E-8C4D-4A3B-9F2E-1D2C3B4A5E6F', PathMask::mask('/v1/servers/0B7E6F1E-8C4D-4A3B-9F2E-1D2C3B4A5E6F'));
    }

    public function test_a_segment_of_16_or_more_characters_mixing_letters_and_digits_is_masked()
    {
        $this->assertSame('/services/T0123ABCD/B0123ABCD/*****', PathMask::mask('/services/T0123ABCD/B0123ABCD/abcdefghijklmnopqrstuvwx'.'0'));
        $this->assertSame('/download/*****', PathMask::mask('/download/abcdefghijklmno1'));
        $this->assertSame('/download/abcdefghijklmn1', PathMask::mask('/download/abcdefghijklmn1'));
    }

    public function test_long_segments_without_both_letters_and_digits_are_kept()
    {
        $this->assertSame('/repos/rocketeers-app/laravel-integration', PathMask::mask('/repos/rocketeers-app/laravel-integration'));
        $this->assertSame('/orders/12345678901234567890', PathMask::mask('/orders/12345678901234567890'));
    }

    public function test_ordinary_paths_are_kept()
    {
        $this->assertSame('/', PathMask::mask('/'));
        $this->assertSame('/v1/items/42', PathMask::mask('/v1/items/42'));
        $this->assertSame('/v1/items/abc123', PathMask::mask('/v1/items/abc123'));
        $this->assertSame('/a//b/', PathMask::mask('/a//b/'));
    }
}
