<?php

namespace Rocketeers\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Rocketeers\Laravel\Concerns\ExtractsExceptionCode;
use Rocketeers\Rocketeers;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;

class RocketeersLoggerHandler extends AbstractProcessingHandler
{
    use ExtractsExceptionCode;

    protected $client;

    public function __construct(Rocketeers $client, $level = Logger::DEBUG, $bubble = true)
    {
        $this->client = $client;

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! in_array(app()->environment(), config('rocketeers.environments'))) {
            return;
        }

        if (! isset($record['context']['exception'])) {
            return;
        }

        $request = app('request');
        $user = app('auth')?->user();

        try {
            $this->client->report([
                'channel' => $record['channel'],
                'environment' => app()->environment(),
                'code' => $this->getCodeFromException($record['context']['exception'] ?? null),
                'exception' => $this->getException($record),
                'message' => $this->getMessage($record),
                'context' => $record['context'],
                'datetime' => $record['datetime'],
                'extra' => $record['extra'] ?: null,
                'level' => $record['level'],
                'level_name' => $record['level_name'],
                'file' => $this->getFile($record),
                'line' => $this->getLine($record),
                'trace' => $this->getTrace($record),
                'method' => app()->runningInConsole() ? null : $request->getMethod(),
                'url' => app()->runningInConsole() ? config('app.url') : $request->getUri(),
                'querystring' => $request->query->all() ?: null,
                'referrer' => $request->server('HTTP_REFERER'),
                'headers' => $this->filterSensitiveData($request->headers->all()),
                'cookies' => $this->filterSensitiveData($request->cookies->all()),
                'files' => $this->getFiles($request),
                'inputs' => $this->filterSensitiveData($request->all()) ?: null,
                'sessions' => $this->filterSensitiveData($this->getSession($request)),
                'user_id' => $user?->getKey(),
                'user_email' => $user?->email,
                'user_name' => $user?->name,
                'user_agent' => $request->headers->get('User-Agent'),
                'ip_address' => $request->getClientIp(),
                'hostname' => $this->getHostname($request),
                'command' => trim(implode(' ', $request->server('argv', null) ?: [])),
            ]);
        } catch (\Throwable $e) {
            // Silently fail to prevent infinite error loops
        }
    }

    public function getException(LogRecord $record): ?string
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        if (is_object($exception)) {
            if (method_exists($exception, 'getOriginalClassName')) {
                return $exception->getOriginalClassName();
            }

            return get_class($exception);
        }

        return null;
    }

    public function getMessage(LogRecord $record): ?string
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        return $exception?->getMessage();
    }

    public function getFile(LogRecord $record): ?string
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        return $exception?->getFile();
    }

    public function getLine(LogRecord $record): ?string
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        return $exception?->getLine();
    }

    public function getTrace(LogRecord $record): array|string|null
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        return $exception?->getTrace();
    }

    public function getHostname(Request $request): ?string
    {
        if (! $request->getClientIp() || $request->getClientIp() == '127.0.0.1') {
            return gethostname() ?: null;
        }

        return gethostbyaddr($request->getClientIp());
    }

    public function getSession(Request $request): ?array
    {
        try {
            return $request->getSession() ? $request->session()->all() : null;
        } catch (SessionNotFoundException $exception) {
            return null;
        }
    }

    protected function getFiles(Request $request): array
    {
        if (is_null($request->files)) {
            return [];
        }

        return $this->mapFiles($request->files->all());
    }

    protected function mapFiles(array $files): array
    {
        return array_map(function ($file) {
            if (is_array($file)) {
                return $this->mapFiles($file);
            }

            if (! $file instanceof UploadedFile) {
                return;
            }

            try {
                $fileSize = $file->getSize();
            } catch (\RuntimeException $e) {
                $fileSize = 0;
            }

            try {
                $mimeType = $file->getMimeType();
            } catch (\Exception $e) {
                $mimeType = 'undefined';
            }

            return [
                'pathname' => $file->getPathname(),
                'size' => $fileSize,
                'mimeType' => $mimeType,
            ];
        }, $files);
    }

    protected function filterSensitiveData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sensitiveFields = config('rocketeers.sensitive_fields', [
            'password', 'password_confirmation', 'token', 'secret',
            'credit_card', 'card_number', 'cvv', 'ssn', 'authorization',
        ]);

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields)) {
                $data[$key] = '********';
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSensitiveData($value);
            }
        }

        return $data;
    }
}
