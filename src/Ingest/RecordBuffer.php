<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * Collects the outgoing-request records of the running execution, so they reach the agent in one frame
 * once the response has been sent or the job is done rather than costing a connection per request. A
 * full buffer is flushed straight away, and so is one a long-running process has been filling for a
 * while. Records the agent does not take are dropped: telemetry is never retried from the app.
 */
class RecordBuffer
{
    public const MAX_LINES = 500;

    public const MAX_BYTES = 1048576;

    public const MAX_AGE_SECONDS = 10;

    /** @var AgentConnection */
    protected $connection;

    /** @var string[] */
    protected $lines = [];

    /** @var int */
    protected $bytes = 0;

    /** @var float|null */
    protected $since;

    public function __construct(AgentConnection $connection)
    {
        $this->connection = $connection;
    }

    public function push(string $line): void
    {
        $this->lines[] = $line;
        $this->bytes += strlen($line) + 1;
        $this->since = $this->since ?? microtime(true);

        if (count($this->lines) >= self::MAX_LINES
            || $this->bytes >= self::MAX_BYTES
            || microtime(true) - $this->since >= self::MAX_AGE_SECONDS) {
            $this->flush();
        }
    }

    /**
     * @return string[]
     */
    public function pull(): array
    {
        $lines = $this->lines;

        $this->lines = [];
        $this->bytes = 0;
        $this->since = null;

        return $lines;
    }

    public function count(): int
    {
        return count($this->lines);
    }

    /**
     * @return string|null The agent's reply, or null when nothing was sent.
     */
    public function flush(): ?string
    {
        $lines = $this->pull();

        if ($lines === [] || ! $this->connection->present()) {
            return null;
        }

        return $this->connection->send($lines);
    }
}
