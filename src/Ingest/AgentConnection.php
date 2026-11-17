<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * Hands records to the local agent, one short-lived connection per frame. Every step has a tight
 * timeout and every failure is silent, because a missing or stuck agent must never slow the app down
 * or break it: the caller falls back (errors) or drops (telemetry). An agent that did not answer is
 * left alone for a few seconds, so a long-running worker does not pay for the same failure on every
 * flush.
 */
class AgentConnection
{
    public const CONNECT_TIMEOUT = 0.05;

    public const IO_TIMEOUT_MICROSECONDS = 250000;

    public const ABSENT_SECONDS = 10;

    /** @var array<string, float> */
    protected static $absentUntil = [];

    /** @var AgentEndpoint */
    protected $endpoint;

    /** @var string */
    protected $key;

    public function __construct(AgentEndpoint $endpoint, string $key)
    {
        $this->endpoint = $endpoint;
        $this->key = $key;
    }

    public function endpoint(): AgentEndpoint
    {
        return $this->endpoint;
    }

    public function present(): bool
    {
        $uri = $this->endpoint->uri();

        if (isset(static::$absentUntil[$uri])) {
            if (microtime(true) < static::$absentUntil[$uri]) {
                return false;
            }

            unset(static::$absentUntil[$uri]);
        }

        return $this->endpoint->present();
    }

    /**
     * @param  string[]  $lines  NDJSON records.
     * @return string|null The agent's reply line, or null when nothing was delivered.
     */
    public function send(array $lines): ?string
    {
        return $lines === [] ? null : $this->exchange(implode("\n", $lines));
    }

    /**
     * @return string|null The agent's version, or null when no agent answered.
     */
    public function ping(): ?string
    {
        $reply = $this->exchange(Frame::PING);

        if ($reply === null || strncmp($reply, Frame::PONG, strlen(Frame::PONG)) !== 0) {
            return null;
        }

        return trim(substr($reply, strlen(Frame::PONG)));
    }

    public static function forgetAbsence(): void
    {
        static::$absentUntil = [];
    }

    protected function exchange(string $body): ?string
    {
        if (! $this->present() || ($frame = Frame::encode($this->key, $body)) === null) {
            return null;
        }

        $socket = @stream_socket_client($this->endpoint->uri(), $errno, $error, self::CONNECT_TIMEOUT);

        if (! is_resource($socket)) {
            $this->markAbsent();

            return null;
        }

        try {
            stream_set_timeout($socket, 0, self::IO_TIMEOUT_MICROSECONDS);

            if (! $this->write($socket, $frame)) {
                return null;
            }

            $reply = @fgets($socket, 128);

            if (! is_string($reply) || substr($reply, -1) !== "\n") {
                $this->markAbsentWhenTimedOut($socket);

                return null;
            }

            return rtrim($reply, "\r\n");
        } finally {
            @fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    protected function write($socket, string $frame): bool
    {
        $length = strlen($frame);

        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = @fwrite($socket, $written === 0 ? $frame : substr($frame, $written));

            if (! is_int($bytes) || $bytes === 0) {
                $this->markAbsentWhenTimedOut($socket);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  resource  $socket
     */
    protected function markAbsentWhenTimedOut($socket): void
    {
        if (stream_get_meta_data($socket)['timed_out'] ?? false) {
            $this->markAbsent();
        }
    }

    protected function markAbsent(): void
    {
        static::$absentUntil[$this->endpoint->uri()] = microtime(true) + self::ABSENT_SECONDS;
    }
}
