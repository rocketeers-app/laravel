<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Ingest\Frame;
use Rocketeers\Laravel\Ingest\IngestKey;

class FrameTest extends TestCase
{
    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = IngestKey::fromToken('test-token');
    }

    public function test_the_key_is_the_first_32_hex_characters_of_an_hmac_of_the_token()
    {
        $this->assertSame(substr(hash_hmac('sha256', 'rocketeers-ingest', 'test-token'), 0, 32), $this->key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->key);
        $this->assertNotSame($this->key, IngestKey::fromToken('other-token'));
    }

    public function test_a_frame_survives_a_round_trip()
    {
        $body = "{\"t\":\"error\",\"v\":1}\n{\"t\":\"outgoing_request\",\"v\":1}";
        $frame = Frame::encode($this->key, $body);

        $this->assertSame(strlen('v1:'.$this->key.':'.$body).':v1:'.$this->key.':'.$body, $frame);
        $this->assertSame([Frame::COMPLETE, $body], Frame::parse($frame, $this->key));
        $this->assertSame([Frame::COMPLETE, 'PING'], Frame::parse(Frame::encode($this->key, Frame::PING), $this->key));
    }

    public function test_a_frame_arriving_in_pieces_is_incomplete_until_its_last_byte()
    {
        $frame = Frame::encode($this->key, '{"t":"error","v":1,"payload":{"message":"a:b:c"}}');

        for ($length = 0; $length < strlen($frame); $length++) {
            $this->assertSame([Frame::INCOMPLETE, null], Frame::parse(substr($frame, 0, $length), $this->key), "after {$length} bytes");
        }

        $this->assertSame([Frame::COMPLETE, '{"t":"error","v":1,"payload":{"message":"a:b:c"}}'], Frame::parse($frame, $this->key));
    }

    public function test_reads_of_any_size_add_up_to_the_frame()
    {
        $body = str_repeat('{"t":"outgoing_request","v":1,"path":"/a"}'."\n", 2000);
        $frame = Frame::encode($this->key, $body);
        $buffer = '';

        foreach (str_split($frame, 8191) as $chunk) {
            $this->assertSame(Frame::INCOMPLETE, Frame::parse($buffer, $this->key)[0]);

            $buffer .= $chunk;
        }

        $this->assertSame([Frame::COMPLETE, $body], Frame::parse($buffer, $this->key));
    }

    public function test_bytes_after_the_frame_are_ignored()
    {
        $this->assertSame([Frame::COMPLETE, 'PING'], Frame::parse(Frame::encode($this->key, 'PING').'trailing', $this->key));
    }

    public function test_a_frame_over_4_mib_is_never_encoded_and_refused_before_it_is_read()
    {
        $limit = Frame::MAX_BYTES - strlen('v1:'.$this->key.':');

        $this->assertNotNull(Frame::encode($this->key, str_repeat('x', $limit)));
        $this->assertNull(Frame::encode($this->key, str_repeat('x', $limit + 1)));

        $this->assertSame([Frame::REJECTED, Frame::ERR_SIZE], Frame::parse((Frame::MAX_BYTES + 1).':v1:', $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_SIZE], Frame::parse('12345678', $this->key));
    }

    public function test_a_frame_signed_with_another_key_is_refused()
    {
        $frame = Frame::encode(IngestKey::fromToken('rotated-token'), '{"t":"error","v":1}');

        $this->assertSame([Frame::REJECTED, Frame::ERR_KEY], Frame::parse($frame, $this->key));
    }

    public function test_a_wrong_key_is_refused_as_soon_as_it_has_arrived()
    {
        $frame = Frame::encode(IngestKey::fromToken('rotated-token'), str_repeat('{"t":"error"}', 100000));
        $header = strpos($frame, ':') + 1 + strlen('v1:'.$this->key.':');

        $this->assertSame([Frame::INCOMPLETE, null], Frame::parse(substr($frame, 0, $header - 1), $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_KEY], Frame::parse(substr($frame, 0, $header), $this->key));
    }

    public function test_a_frame_without_a_header_is_refused_without_waiting_for_the_rest()
    {
        $this->assertSame([Frame::INCOMPLETE, null], Frame::parse('4000000:'.str_repeat('x', 63), $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('4000000:'.str_repeat('x', 64), $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('4000000:v1:'.str_repeat('x', 64), $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_VERSION], Frame::parse('4000000:v9:', $this->key));
    }

    public function test_a_frame_of_another_protocol_version_is_refused()
    {
        $payload = 'v2:'.$this->key.':PING';

        $this->assertSame([Frame::REJECTED, Frame::ERR_VERSION], Frame::parse(strlen($payload).':'.$payload, $this->key));
    }

    public function test_garbage_is_refused_as_malformed()
    {
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('GET / HTTP/1.1', $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('0:', $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('5:hello', $this->key));
        $this->assertSame([Frame::REJECTED, Frame::ERR_MALFORMED], Frame::parse('35:v1:'.$this->key, $this->key));
    }
}
