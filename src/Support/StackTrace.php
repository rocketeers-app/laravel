<?php

namespace Rocketeers\Laravel\Support;

use Throwable;

/**
 * Turns an exception into the frames the error page shows, the way spatie/backtrace does: the throw site comes first
 * and every frame carries its own function, the code around its line and whether it lies outside vendor/.
 */
class StackTrace
{
    public const SNIPPET_RADIUS = 14;

    public const MAX_LINE_LENGTH = 250;

    public const MAX_BYTES = 524288;

    public const MAX_FRAMES = 50;

    public static function frames(Throwable $exception, string $basePath): array
    {
        $basePath = rtrim($basePath === '' ? '' : (realpath($basePath) ?: $basePath), '/\\');
        $file = $exception->getFile();
        $line = $exception->getLine();
        $sources = [];
        $frames = [];

        foreach ($exception->getTrace() as $entry) {
            $frames[] = self::frame($file, $line, $entry['class'] ?? null, $entry['function'] ?? null, $entry['type'] ?? null, $basePath, $sources);

            $file = $entry['file'] ?? null;
            $line = $entry['line'] ?? null;
        }

        $frames[] = self::frame($file, $line, null, '[top]', null, $basePath, $sources);

        return self::limited($frames);
    }

    private static function frame(?string $file, ?int $line, ?string $class, ?string $function, ?string $type, string $basePath, array &$sources): array
    {
        $relativeFile = self::relative($file, $basePath);

        return [
            'file' => $relativeFile,
            'line' => $line,
            'class' => $class,
            'function' => $function,
            'type' => $type,
            'application_frame' => $relativeFile !== null && strpos($relativeFile, 'vendor/') !== 0,
            'code_snippet' => self::snippet($file, $line, $sources),
        ];
    }

    private static function relative(?string $file, string $basePath): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        if ($basePath !== '' && strpos($file, $basePath.DIRECTORY_SEPARATOR) === 0) {
            return str_replace('\\', '/', substr($file, strlen($basePath) + 1));
        }

        return $file;
    }

    private static function snippet(?string $file, ?int $line, array &$sources): ?array
    {
        if ($file === null || $line === null || $line < 1) {
            return null;
        }

        if (! array_key_exists($file, $sources)) {
            $sources[$file] = is_file($file) && is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
        }

        $lines = $sources[$file];

        if ($lines === false || $line > count($lines)) {
            return null;
        }

        $size = self::SNIPPET_RADIUS * 2 + 1;
        $end = min(max($line - self::SNIPPET_RADIUS, 1) + $size - 1, count($lines));
        $start = max($end - $size + 1, 1);
        $snippet = [];

        for ($number = $start; $number <= $end; $number++) {
            $snippet[$number] = mb_substr(rtrim($lines[$number - 1]), 0, self::MAX_LINE_LENGTH);
        }

        return $snippet;
    }

    private static function limited(array $frames): array
    {
        if (self::size($frames) <= self::MAX_BYTES) {
            return $frames;
        }

        $frames = array_map(function (array $frame) {
            return $frame['application_frame'] ? $frame : array_merge($frame, ['code_snippet' => null]);
        }, $frames);

        if (self::size($frames) <= self::MAX_BYTES) {
            return $frames;
        }

        return array_slice($frames, 0, self::MAX_FRAMES);
    }

    private static function size(array $frames): int
    {
        return strlen((string) json_encode($frames, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
