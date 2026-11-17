<?php

namespace Rocketeers\Laravel\Agent;

use Rocketeers\Laravel\Ingest\Uuid;

/**
 * Records uploaded together in one request. The id stays the same on every retry, which is how the
 * server recognises a batch it already processed when only its response got lost.
 */
class Batch
{
    /** @var string */
    public $id;

    /** @var string[] */
    public $lines;

    /** @var float When its oldest record reached the agent. */
    public $createdAt;

    /**
     * @param  string[]  $lines
     */
    public function __construct(array $lines, float $createdAt, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v4();
        $this->lines = array_values($lines);
        $this->createdAt = $createdAt;
    }

    public function body(): string
    {
        return implode("\n", $this->lines);
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function errors(): int
    {
        $errors = 0;

        foreach ($this->lines as $line) {
            $errors += Spool::isError($line) ? 1 : 0;
        }

        return $errors;
    }

    /**
     * Two halves with ids of their own, for a server that found this batch too large.
     *
     * @return Batch[]
     */
    public function split(): array
    {
        $half = (int) ceil(count($this->lines) / 2);

        return [
            new static(array_slice($this->lines, 0, $half), $this->createdAt),
            new static(array_slice($this->lines, $half), $this->createdAt),
        ];
    }
}
