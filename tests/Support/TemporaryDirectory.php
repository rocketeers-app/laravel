<?php

namespace Rocketeers\Laravel\Tests\Support;

/**
 * Socket paths must stay under 104 bytes on macOS, so these live directly in /tmp where it exists.
 */
class TemporaryDirectory
{
    public static function create(): string
    {
        $base = is_dir('/tmp') && is_writable('/tmp') ? '/tmp' : rtrim(sys_get_temp_dir(), '/');
        $path = $base.'/rkt-'.bin2hex(random_bytes(4));

        mkdir($path, 0700);

        return $path;
    }

    public static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($path.'/'.$entry) ? static::remove($path.'/'.$entry) : @unlink($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
