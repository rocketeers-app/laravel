<?php

namespace Rocketeers\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Agent\Agent;
use Rocketeers\Laravel\Agent\Backoff;
use Rocketeers\Laravel\Agent\Log;
use Rocketeers\Laravel\Agent\Spool;
use Rocketeers\Laravel\Agent\Uploader;
use Rocketeers\Laravel\Ingest\Frame;
use Rocketeers\Laravel\Ingest\IngestKey;
use Rocketeers\Laravel\Ingest\Uuid;
use Rocketeers\Laravel\Tests\Support\FakeClock;
use Rocketeers\Laravel\Tests\Support\FakeTransport;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;

class AgentTest extends TestCase
{
    protected const ERROR = '{"t":"error","v":1,"trace_id":null,"payload":{"message":"boom"}}';

    protected const REQUEST = '{"t":"outgoing_request","v":1,"path":"/a"}';

    protected string $directory;

    protected string $socket;

    protected string $key;

    protected FakeClock $clock;

    protected FakeTransport $transport;

    protected Agent $agent;

    /** @var array<int, array{0: string, 1: string}> */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = TemporaryDirectory::create();
        $this->socket = $this->directory.'/agent.sock';
        $this->key = IngestKey::fromToken('test-token');
        $this->clock = new FakeClock;
        $this->transport = new FakeTransport;
        $this->agent = $this->agent();
        $this->agent->listen();
    }

    protected function tearDown(): void
    {
        $this->agent->stop();
        $this->agent->tick(0);

        TemporaryDirectory::remove($this->directory);

        parent::tearDown();
    }

    public function test_it_answers_a_ping_with_its_version()
    {
        $this->assertSame('PONG 2.24.0', $this->send(Frame::encode($this->key, Frame::PING)));
    }

    public function test_the_socket_is_only_writable_by_owner_and_group()
    {
        $this->assertSame('socket', filetype($this->socket));
        $this->assertSame(0660, fileperms($this->socket) & 0777);
    }

    public function test_it_takes_records_and_answers_ok()
    {
        $this->assertSame(Frame::OK, $this->send($this->frame([self::ERROR, self::REQUEST])));
        $this->assertSame(2, $this->agent->spool()->count());
    }

    public function test_a_frame_may_arrive_in_pieces()
    {
        $frame = $this->frame([self::ERROR]);
        $client = $this->connect();

        fwrite($client, substr($frame, 0, 10));
        $this->agent->tick(0.05);
        fwrite($client, substr($frame, 10, 30));
        $this->agent->tick(0.05);
        fwrite($client, substr($frame, 40));

        $this->assertSame(Frame::OK, $this->reply($client));
    }

    public function test_a_frame_signed_with_another_key_is_refused_with_a_hint_logged_once()
    {
        $frame = Frame::encode(IngestKey::fromToken('old-token'), self::ERROR);

        $this->assertSame(Frame::ERR_KEY, $this->send($frame));
        $this->assertSame(Frame::ERR_KEY, $this->send($frame));
        $this->assertCount(1, $this->logged('ROCKETEERS_API_TOKEN'));
        $this->assertSame(0, $this->agent->spool()->count());
    }

    public function test_other_versions_oversized_frames_and_garbage_are_refused()
    {
        $payload = 'v2:'.$this->key.':PING';

        $this->assertSame(Frame::ERR_VERSION, $this->send(strlen($payload).':'.$payload));
        $this->assertSame(Frame::ERR_SIZE, $this->send((Frame::MAX_BYTES + 1).':v1:'));
        $this->assertSame(Frame::ERR_MALFORMED, $this->send("GET / HTTP/1.1\r\n\r\n"));
        $this->assertSame(Frame::ERR_MALFORMED, $this->send(Frame::encode($this->key, "\n\n")));
    }

    public function test_a_stalled_client_is_disconnected_after_five_seconds()
    {
        $client = $this->connect();

        fwrite($client, '120:v1:');
        $this->agent->tick(0.05);

        $this->clock->advance(Agent::CONNECTION_SECONDS);
        $this->agent->tick(0);

        stream_set_blocking($client, true);
        stream_set_timeout($client, 1);

        $this->assertSame('', (string) fread($client, 10));
        $this->assertTrue(feof($client));
    }

    public function test_errors_go_out_after_a_second_in_a_batch_that_carries_them_first()
    {
        $this->send($this->frame([self::REQUEST]));
        $this->send($this->frame([self::ERROR]));

        $this->tick();
        $this->assertCount(0, $this->transport->posts);

        $this->clock->advance(Spool::ERROR_WAIT_SECONDS);
        $this->tick();

        $this->assertCount(1, $this->transport->posts);
        $this->assertSame([self::ERROR, self::REQUEST], $this->transport->lines(0));
        $this->assertSame('https://api.rocketeers.test/v1/ingest', $this->transport->posts[0]['url']);
        $this->assertSame([
            'Authorization: Bearer test-token',
            'Content-Type: application/x-ndjson',
            'Content-Encoding: gzip',
            'X-Rocketeers-Batch: '.$this->transport->posts[0]['id'],
            'X-Rocketeers-Server: web-1',
            'X-Rocketeers-Agent: 2.24.0',
            'Accept: application/json',
            'Expect:',
        ], $this->transport->posts[0]['headers']);
        $this->assertTrue(Uuid::isValid($this->transport->posts[0]['id']));

        $this->tick();

        $this->assertTrue($this->agent->spool()->isEmpty());
        $this->assertCount(1, $this->transport->posts);
    }

    public function test_outgoing_requests_alone_wait_ten_seconds_to_fill_a_batch()
    {
        $this->send($this->frame([self::REQUEST]));

        $this->clock->advance(9.9);
        $this->tick();
        $this->assertCount(0, $this->transport->posts);

        $this->clock->advance(0.1);
        $this->tick();
        $this->assertCount(1, $this->transport->posts);
    }

    public function test_a_failed_upload_is_retried_after_the_backoff_with_the_same_batch_id()
    {
        $this->transport->script = [503, ['status' => 0, 'error' => 'Could not resolve host']];

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();

        $this->assertCount(1, $this->transport->posts);
        $this->assertCount(1, $this->logged('503'));

        $this->clock->advance(0.99);
        $this->tick();
        $this->assertCount(1, $this->transport->posts);

        $this->clock->advance(0.01);
        $this->tick();
        $this->tick();
        $this->assertCount(2, $this->transport->posts);

        $this->clock->advance(2);
        $this->tick();
        $this->tick();

        $this->assertCount(3, $this->transport->posts);
        $this->assertSame([$this->transport->posts[0]['id']], array_unique(array_column($this->transport->posts, 'id')));
        $this->assertSame([self::ERROR], $this->transport->lines(2));
        $this->assertTrue($this->agent->spool()->isEmpty());
    }

    public function test_retry_after_is_honoured()
    {
        $this->transport->script = [['status' => 429, 'retry_after' => '30']];

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();

        $this->clock->advance(29.9);
        $this->tick();
        $this->assertCount(1, $this->transport->posts);

        $this->clock->advance(0.1);
        $this->tick();
        $this->assertCount(2, $this->transport->posts);
    }

    public function test_401_pauses_uploads_and_sends_clients_to_the_api_until_a_batch_gets_through()
    {
        $this->assertPausesOn(401);
    }

    public function test_404_pauses_uploads_too()
    {
        $this->assertPausesOn(404);
    }

    public function test_a_batch_kept_through_pauses_is_given_up_after_two_hours_too()
    {
        $this->transport->script = array_fill(0, 100, 403);

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();

        while (count($this->logged('waiting for 2 hours')) === 0 && count($this->transport->posts) < 50) {
            $this->clock->advance(Uploader::PAUSE_SECONDS);
            $this->tick();
            $this->tick();
        }

        $posts = count($this->transport->posts);

        $this->assertCount(1, $this->logged('waiting for 2 hours'));
        $this->assertGreaterThan(20, $posts);

        $this->clock->advance(Uploader::PAUSE_SECONDS);
        $this->tick();
        $this->tick();

        $this->assertCount($posts, $this->transport->posts);
        $this->assertSame(Frame::OK, $this->send($this->frame([self::ERROR])));
    }

    public function test_413_splits_the_batch_in_halves()
    {
        $this->transport->script = [413];
        $lines = [self::ERROR, self::ERROR, self::REQUEST, self::REQUEST];

        $this->send($this->frame($lines));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();

        $this->assertCount(3, $this->transport->posts);
        $this->assertSame([self::ERROR, self::ERROR], $this->transport->lines(1));
        $this->assertSame([self::REQUEST, self::REQUEST], $this->transport->lines(2));
        $this->assertCount(3, array_unique(array_column($this->transport->posts, 'id')));
    }

    public function test_a_single_record_too_large_for_the_api_is_dropped()
    {
        $this->transport->script = [413];

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();
        $this->tick();

        $this->assertCount(1, $this->transport->posts);
        $this->assertCount(1, $this->logged('too large'));
    }

    public function test_a_malformed_or_rejected_batch_is_dropped()
    {
        $this->transport->script = [400, 422];

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();
        $this->clock->advance(60);
        $this->tick();

        $this->assertCount(2, $this->transport->posts);
        $this->assertTrue($this->agent->spool()->isEmpty());
        $this->assertNotEmpty($this->logged('400'));
    }

    public function test_it_gives_up_on_a_batch_after_two_hours()
    {
        $this->transport->script = array_fill(0, 1000, 503);

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);

        for ($elapsed = 0; $elapsed <= Uploader::GIVE_UP_SECONDS; $elapsed += 60) {
            $this->tick();
            $this->tick();
            $this->clock->advance(60);
        }

        $posts = count($this->transport->posts);

        $this->clock->advance(600);
        $this->tick();
        $this->tick();

        $this->assertSame($posts, count($this->transport->posts));
        $this->assertNotEmpty($this->logged('2 hours'));
    }

    public function test_a_full_error_queue_refuses_errors_but_still_takes_outgoing_requests()
    {
        $this->agent->spool()->push(array_fill(0, 8, str_repeat('e', 1024 * 1024 - 1)), [], $this->clock->now());

        $this->assertSame(Frame::ERR_FULL, $this->send($this->frame([self::ERROR, self::REQUEST])));
        $this->assertSame(Frame::OK, $this->send($this->frame([self::REQUEST])));
    }

    public function test_stopping_removes_the_socket_first_then_uploads_what_is_left()
    {
        $this->send($this->frame([self::REQUEST]));

        $this->agent->stop();
        $this->agent->tick(0);

        $this->assertFileDoesNotExist($this->socket);
        $this->assertCount(1, $this->transport->posts);
        $this->assertFalse($this->agent->finished());

        $this->agent->tick(0);

        $this->assertTrue($this->agent->finished());
    }

    public function test_run_returns_once_everything_is_uploaded()
    {
        $this->send($this->frame([self::ERROR]));

        $this->agent->stop();
        $this->agent->run();

        $this->assertCount(1, $this->transport->posts);
        $this->assertNotEmpty($this->logged('Stopped.'));
    }

    public function test_draining_ends_when_the_drain_time_is_up()
    {
        $this->transport->script = array_fill(0, 100, 503);
        $this->send($this->frame([self::ERROR]));

        $this->agent->stop();
        $this->agent->run(function () {
            $this->clock->advance(1);
        });

        $this->assertNotEmpty($this->logged('1 records not uploaded'));
    }

    public function test_a_socket_left_behind_is_replaced()
    {
        $this->agent->stop();
        $this->agent->tick(0);

        $stale = stream_socket_server('unix://'.$this->socket);
        fclose($stale);

        $this->assertSame('socket', filetype($this->socket));

        $this->agent = $this->agent();
        $this->agent->listen();

        $this->assertSame('PONG 2.24.0', $this->send(Frame::encode($this->key, Frame::PING)));
    }

    protected function assertPausesOn(int $status): void
    {
        $this->transport->script = [$status];

        $this->send($this->frame([self::ERROR]));
        $this->clock->advance(1);
        $this->tick();
        $this->tick();

        $this->assertSame(Frame::ERR_PAUSED, $this->send($this->frame([self::ERROR])));
        $this->assertSame('PONG 2.24.0', $this->send(Frame::encode($this->key, Frame::PING)));
        $this->assertCount(1, $this->logged((string) $status));

        $this->clock->advance(Uploader::PAUSE_SECONDS - 1);
        $this->tick();
        $this->assertCount(1, $this->transport->posts);

        $this->clock->advance(1);
        $this->tick();

        $this->assertCount(2, $this->transport->posts);
        $this->assertSame($this->transport->posts[0]['id'], $this->transport->posts[1]['id']);
        $this->assertSame(Frame::ERR_PAUSED, $this->send($this->frame([self::ERROR])));

        $this->tick();

        $this->assertSame(Frame::OK, $this->send($this->frame([self::ERROR])));
    }

    protected function agent(): Agent
    {
        $log = new Log(function (string $level, string $message) {
            $this->logged[] = [$level, $message];
        }, $this->clock);

        $uploader = new Uploader($this->transport, 'https://api.rocketeers.test/v1/', 'test-token', '2.24.0', 'web-1', new Backoff(function () {
            return 1.0;
        }), $log);

        return new Agent('unix://'.$this->socket, $this->key, $uploader, $log, [
            'version' => '2.24.0',
            'drain' => 5,
            'clock' => $this->clock,
        ]);
    }

    protected function tick(): void
    {
        $this->agent->tick(0);
    }

    /**
     * @param  string[]  $lines
     */
    protected function frame(array $lines): string
    {
        return Frame::encode($this->key, implode("\n", $lines));
    }

    /**
     * @return resource
     */
    protected function connect()
    {
        $client = stream_socket_client('unix://'.$this->socket, $errno, $error, 1);

        $this->assertIsResource($client, $error);

        return $client;
    }

    protected function send(string $frame): string
    {
        $client = $this->connect();

        fwrite($client, $frame);

        return $this->reply($client);
    }

    /**
     * @param  resource  $client
     */
    protected function reply($client): string
    {
        stream_set_blocking($client, false);

        $reply = '';

        for ($i = 0; $i < 50 && substr($reply, -1) !== "\n"; $i++) {
            $this->agent->tick(0.01);

            $reply .= (string) fread($client, 128);
        }

        fclose($client);

        return rtrim($reply, "\n");
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function logged(string $fragment): array
    {
        return array_values(array_filter($this->logged, function (array $entry) use ($fragment) {
            return strpos($entry[1], $fragment) !== false;
        }));
    }
}
