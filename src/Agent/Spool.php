<?php

namespace Rocketeers\Laravel\Agent;

use SplQueue;

/**
 * Holds accepted records until they are uploaded, in two bounded queues. Errors are what clients post
 * themselves when the agent refuses them, so a full error queue refuses new ones rather than losing old
 * ones; outgoing requests are telemetry, so the oldest make room for the newest. Errors wait at most a
 * second and always go first, telemetry waits up to ten seconds to fill a batch.
 */
class Spool
{
    public const ERROR_PREFIX = '{"t":"error"';

    public const MAX_ERROR_BYTES = 8388608;

    public const MAX_OUTGOING_BYTES = 16777216;

    public const MAX_BATCH_BYTES = 1048576;

    public const ERROR_WAIT_SECONDS = 1.0;

    public const OUTGOING_WAIT_SECONDS = 10.0;

    /** @var SplQueue<array{0: string, 1: float}> */
    protected $errors;

    /** @var SplQueue<array{0: string, 1: float}> */
    protected $outgoing;

    /** @var int */
    protected $errorBytes = 0;

    /** @var int */
    protected $outgoingBytes = 0;

    /** @var int */
    protected $dropped = 0;

    public function __construct()
    {
        $this->errors = new SplQueue;
        $this->outgoing = new SplQueue;
    }

    /**
     * The client always encodes the type first, so a prefix is enough to tell an error from the rest.
     */
    public static function isError(string $line): bool
    {
        return strncmp($line, self::ERROR_PREFIX, strlen(self::ERROR_PREFIX)) === 0;
    }

    /**
     * @return array{0: string[], 1: string[]} The error records and all others.
     */
    public static function classify(string $body): array
    {
        $errors = [];
        $others = [];

        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            if (static::isError($line)) {
                $errors[] = $line;
            } else {
                $others[] = $line;
            }
        }

        return [$errors, $others];
    }

    /**
     * Takes all records of a frame, or none of them when its errors do not fit.
     *
     * @param  string[]  $errors
     * @param  string[]  $others
     */
    public function push(array $errors, array $others, float $now): bool
    {
        if ($this->errorBytes + static::bytes($errors) > self::MAX_ERROR_BYTES) {
            return false;
        }

        foreach ($errors as $line) {
            $this->errors->enqueue([$line, $now]);
            $this->errorBytes += strlen($line) + 1;
        }

        foreach ($others as $line) {
            $this->outgoing->enqueue([$line, $now]);
            $this->outgoingBytes += strlen($line) + 1;
        }

        while ($this->outgoingBytes > self::MAX_OUTGOING_BYTES && ! $this->outgoing->isEmpty()) {
            $this->outgoingBytes -= strlen($this->outgoing->dequeue()[0]) + 1;
            $this->dropped++;
        }

        return true;
    }

    public function due(float $now): bool
    {
        return $this->dueIn($now) <= 0;
    }

    /**
     * Seconds until a batch should be uploaded: 0 when one is due, INF when there is nothing to send.
     */
    public function dueIn(float $now): float
    {
        if ($this->errorBytes + $this->outgoingBytes >= self::MAX_BATCH_BYTES) {
            return 0.0;
        }

        $dueIn = INF;

        if (! $this->errors->isEmpty()) {
            $dueIn = min($dueIn, $this->errors->bottom()[1] + self::ERROR_WAIT_SECONDS - $now);
        }

        if (! $this->outgoing->isEmpty()) {
            $dueIn = min($dueIn, $this->outgoing->bottom()[1] + self::OUTGOING_WAIT_SECONDS - $now);
        }

        return max(0.0, $dueIn);
    }

    /**
     * The next batch of at most MAX_BATCH_BYTES, errors first. A single record larger than that still
     * makes a batch of its own: the server decides whether it is too large.
     */
    public function take(): ?Batch
    {
        $lines = [];
        $bytes = 0;
        $oldest = null;

        if ($this->takeFrom($this->errors, $this->errorBytes, $lines, $bytes, $oldest)) {
            $this->takeFrom($this->outgoing, $this->outgoingBytes, $lines, $bytes, $oldest);
        }

        return $lines === [] ? null : new Batch($lines, (float) $oldest);
    }

    public function isEmpty(): bool
    {
        return $this->errors->isEmpty() && $this->outgoing->isEmpty();
    }

    public function count(): int
    {
        return $this->errors->count() + $this->outgoing->count();
    }

    public function size(): int
    {
        return $this->errorBytes + $this->outgoingBytes;
    }

    /**
     * Outgoing records dropped to make room since the last call.
     */
    public function pullDropped(): int
    {
        $dropped = $this->dropped;
        $this->dropped = 0;

        return $dropped;
    }

    /**
     * @param  SplQueue<array{0: string, 1: float}>  $queue
     * @param  string[]  $lines
     * @return bool Whether the queue was emptied with room to spare.
     */
    protected function takeFrom(SplQueue $queue, int &$queueBytes, array &$lines, int &$bytes, ?float &$oldest): bool
    {
        while (! $queue->isEmpty()) {
            [$line, $at] = $queue->bottom();
            $size = strlen($line) + 1;

            if ($lines !== [] && $bytes + $size > self::MAX_BATCH_BYTES) {
                return false;
            }

            $queue->dequeue();
            $queueBytes -= $size;
            $lines[] = $line;
            $bytes += $size;
            $oldest = $oldest === null ? $at : min($oldest, $at);
        }

        return true;
    }

    /**
     * @param  string[]  $lines
     */
    protected static function bytes(array $lines): int
    {
        $bytes = 0;

        foreach ($lines as $line) {
            $bytes += strlen($line) + 1;
        }

        return $bytes;
    }
}
