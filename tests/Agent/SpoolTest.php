<?php

namespace Rocketeers\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Agent\Spool;
use Rocketeers\Laravel\Ingest\Uuid;

class SpoolTest extends TestCase
{
    public function test_records_are_told_apart_by_their_leading_type()
    {
        $body = implode("\n", [
            '{"t":"outgoing_request","v":1,"path":"/a"}',
            '{"t":"error","v":1,"trace_id":null,"payload":{"message":"boom"}}',
            '',
            '{"t":"something_new","v":3}',
        ]);

        $this->assertSame([
            ['{"t":"error","v":1,"trace_id":null,"payload":{"message":"boom"}}'],
            ['{"t":"outgoing_request","v":1,"path":"/a"}', '{"t":"something_new","v":3}'],
        ], Spool::classify($body));
    }

    public function test_errors_go_first()
    {
        $spool = new Spool;

        $spool->push([], ['{"t":"outgoing_request","n":1}'], 100.0);
        $spool->push(['{"t":"error","n":2}'], ['{"t":"outgoing_request","n":3}'], 101.0);

        $batch = $spool->take();

        $this->assertSame(['{"t":"error","n":2}', '{"t":"outgoing_request","n":1}', '{"t":"outgoing_request","n":3}'], $batch->lines);
        $this->assertSame(100.0, $batch->createdAt);
        $this->assertTrue(Uuid::isValid($batch->id));
        $this->assertTrue($spool->isEmpty());
        $this->assertNull($spool->take());
    }

    public function test_batches_stay_within_1_mib()
    {
        $spool = new Spool;
        $line = '{"t":"outgoing_request","pad":"'.str_repeat('x', 1000).'"}';

        $spool->push([], array_fill(0, 2500, $line), 100.0);

        $sizes = [];

        while ($batch = $spool->take()) {
            $this->assertLessThanOrEqual(Spool::MAX_BATCH_BYTES, strlen($batch->body()) + 1);

            $sizes[] = $batch->count();
        }

        $this->assertSame(2500, array_sum($sizes));
        $this->assertCount(3, $sizes);
    }

    public function test_a_single_record_over_1_mib_still_gets_a_batch_of_its_own()
    {
        $spool = new Spool;
        $huge = '{"t":"error","payload":"'.str_repeat('x', Spool::MAX_BATCH_BYTES).'"}';

        $spool->push([$huge, '{"t":"error","n":2}'], [], 100.0);

        $this->assertSame([$huge], $spool->take()->lines);
        $this->assertSame(['{"t":"error","n":2}'], $spool->take()->lines);
    }

    public function test_a_full_error_queue_takes_nothing_from_a_frame_with_errors()
    {
        $spool = new Spool;
        $line = $this->lineOfOneMebibyte('{"t":"error","pad":"');

        for ($i = 0; $i < 8; $i++) {
            $this->assertTrue($spool->push([$line], [], 100.0));
        }

        $count = $spool->count();

        $this->assertFalse($spool->push(['{"t":"error","n":1}'], ['{"t":"outgoing_request"}'], 101.0));
        $this->assertSame($count, $spool->count());
        $this->assertTrue($spool->push([], ['{"t":"outgoing_request"}'], 101.0));
    }

    public function test_the_oldest_outgoing_requests_make_room_for_new_ones()
    {
        $spool = new Spool;
        $line = $this->lineOfOneMebibyte('{"t":"outgoing_request","pad":"');

        $spool->push([], ['{"t":"outgoing_request","first":true}'], 100.0);

        for ($i = 0; $i < 16; $i++) {
            $spool->push([], [$line], 101.0);
        }

        $this->assertSame(1, $spool->pullDropped());
        $this->assertSame(0, $spool->pullDropped());
        $this->assertLessThanOrEqual(Spool::MAX_OUTGOING_BYTES, $spool->size());
        $this->assertStringNotContainsString('"first"', $spool->take()->body());
    }

    public function test_errors_are_due_after_a_second()
    {
        $spool = new Spool;
        $spool->push(['{"t":"error"}'], [], 100.0);

        $this->assertFalse($spool->due(100.5));
        $this->assertEqualsWithDelta(0.5, $spool->dueIn(100.5), 0.0001);
        $this->assertTrue($spool->due(101.0));
    }

    public function test_outgoing_requests_are_due_after_ten_seconds()
    {
        $spool = new Spool;
        $spool->push([], ['{"t":"outgoing_request"}'], 100.0);

        $this->assertFalse($spool->due(109.9));
        $this->assertTrue($spool->due(110.0));
    }

    public function test_a_megabyte_waiting_is_due_at_once()
    {
        $spool = new Spool;
        $spool->push([], [str_repeat('x', Spool::MAX_BATCH_BYTES)], 100.0);

        $this->assertTrue($spool->due(100.0));
    }

    public function test_an_empty_spool_is_never_due()
    {
        $this->assertFalse((new Spool)->due(PHP_INT_MAX));
        $this->assertSame(INF, (new Spool)->dueIn(100.0));
    }

    /**
     * A record that takes exactly 1 MiB of a queue, its newline included.
     */
    protected function lineOfOneMebibyte(string $start): string
    {
        return $start.str_repeat('x', 1024 * 1024 - 1 - strlen($start) - 2).'"}';
    }
}
