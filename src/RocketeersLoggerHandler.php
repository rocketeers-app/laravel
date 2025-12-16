<?php

namespace Rocketeers\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Rocketeers\Rocketeers;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;

class RocketeersLoggerHandler extends AbstractProcessingHandler
{
    protected $client;

    protected $request;

    public function __construct(Rocketeers $client, $level = Logger::DEBUG, $bubble = true)
    {
        $this->client = $client;
        $this->request = Request::createFromGlobals();

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

        $user = app('auth')?->user();

        $this->client->report([
            'channel' => $record['channel'], // not saved currently
            'environment' => app()->environment(),
            'code' => $this->getCodeFromException($record),
            'exception' => $this->getException($record),
            'message' => $this->getMessage($record),
            'context' => $record['context'], // not saved currently
            'datetime' => $record['datetime'], // not saved currently
            'extra' => $record['extra'] ?: null, // not saved currently
            'level' => $record['level'], // not saved currently
            'level_name' => $record['level_name'], // not saved currently
            'file' => $this->getFile($record),
            'line' => $this->getLine($record),
            'trace' => $this->getTrace($record),
            'method' => app()->runningInConsole() ? null : $this->request->getMethod(),
            'url' => app()->runningInConsole() ? config('app.url') : $this->request->getUri(),
            'querystring' => $this->request->query->all() ?: null,
            'referrer' => $this->request->server('HTTP_REFERER'),
            'headers' => $this->request->headers->all(),
            'cookies' => $this->request->cookies->all(),
            'files' => $this->getFiles(),
            'inputs' => $this->request->all() ?: null,
            'sessions' => $this->getSession(),
            'user_id' => $user?->getKey(),
            'user_email' => $user?->email,
            'user_name' => $user?->name,
            'user_agent' => $this->request->headers->get('User-Agent'),
            'ip_address' => $this->request->getClientIp(),
            'hostname' => $this->getHostname(),
            'command' => trim(implode(' ', $this->request->server('argv', null) ?: [])),
        ]);
    }

    public function getException(LogRecord $record): ?string
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_string($exception)) {
            return $exception;
        }

        if (is_object($exception)) {
            if (method_exists($record['context']['exception'], 'getOriginalClassName')) {
                return $record['context']['exception']->getOriginalClassName();
            } else {
                return get_class($record['context']['exception']);
            }
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

    public function getHostname()
    {
        if (! $this->request->getClientIp() || $this->request->getClientIp() == '127.0.0.1') {
            return shell_exec('hostname');
        }

        return gethostbyaddr($this->request->getClientIp());
    }

    public function getSession()
    {
        try {
            $session = $this->request->getSession() ? $this->request->session()->all() : null;
        } catch (SessionNotFoundException $exception) {
            $session = null;
        }

        return $session;
    }

    protected function getCodeFromException(LogRecord $record): ?int
    {
        $exception = $record['context']['exception'] ?? null;

        if (is_object($exception) && method_exists($exception, 'getStatusCode')) {
            return (int) $exception->getStatusCode();
        }

        if (is_object($exception) && method_exists($exception, 'getCode')) {
            return (int) $exception->getCode();
        }

        return null;
    }

    protected function getFiles(): array
    {
        if (is_null($this->request->files)) {
            return [];
        }

        return $this->mapFiles($this->request->files->all());
    }

    protected function mapFiles(array $files)
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
}
