<?php

namespace Rocketeers\Laravel\Agent;

use Rocketeers\Laravel\Ingest\Frame;

/**
 * One app process delivering one frame, possibly over several reads, before it gets its reply and the
 * connection is closed. The deadline keeps a stalled client from holding one of the agent's few
 * connection slots.
 */
class ClientConnection
{
    public const READ_BYTES = 262144;

    /** @var resource */
    protected $socket;

    /** @var string */
    protected $buffer = '';

    /** @var float */
    protected $deadline;

    /**
     * @param  resource  $socket
     */
    public function __construct($socket, float $deadline)
    {
        $this->socket = $socket;
        $this->deadline = $deadline;

        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
    }

    /**
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * Read what has arrived. False once the client has gone away.
     */
    public function read(): bool
    {
        $data = @fread($this->socket, self::READ_BYTES);

        if ($data === false || ($data === '' && feof($this->socket))) {
            return false;
        }

        $this->buffer .= $data;

        return true;
    }

    /**
     * @return array{0: string, 1: string|null} See Frame::parse().
     */
    public function frame(string $key): array
    {
        return Frame::parse($this->buffer, $key);
    }

    public function expired(float $now): bool
    {
        return $now >= $this->deadline;
    }

    public function reply(string $line): void
    {
        @fwrite($this->socket, $line."\n");

        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->buffer = '';
    }
}
