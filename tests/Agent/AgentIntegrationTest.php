<?php

namespace Rocketeers\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\Frame;
use Rocketeers\Laravel\Ingest\IngestKey;
use Rocketeers\Laravel\Ingest\Uuid;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;

/**
 * The agent core in a process of its own (tests/Fixtures/agent.php), uploading over real HTTP to a fake
 * ingest endpoint served by `php -S` (tests/Fixtures/upstream.php).
 */
class AgentIntegrationTest extends TestCase
{
    protected const LINE = '{"t":"error","v":1,"trace_id":null,"payload":{"message":"integration","report_id":"0b7e6f1e-8c4d-4a3b-9f2e-1d2c3b4a5e6f"}}';

    protected string $directory;

    protected string $socket;

    protected int $port;

    /** @var resource|null */
    protected $upstream;

    /** @var resource|null */
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'curl_multi_init', 'gzdecode'] as $function) {
            if (! function_exists($function)) {
                $this->markTestSkipped($function.'() is not available.');
            }
        }

        $this->directory = TemporaryDirectory::create();
        $this->socket = $this->directory.'/agent.sock';
        $this->port = $this->freePort();

        AgentConnection::forgetAbsence();

        $this->startUpstream();
    }

    protected function tearDown(): void
    {
        foreach ([$this->agent, $this->upstream] as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }

        TemporaryDirectory::remove($this->directory);

        parent::tearDown();
    }

    public function test_a_frame_from_the_app_arrives_gzipped_with_the_token_and_a_batch_id()
    {
        $this->startAgent();

        $this->assertSame(Frame::OK, $this->connection()->send([self::LINE]));

        $request = $this->waitForRequests(1)[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('/v1/ingest', $request['uri']);
        $this->assertSame(202, $request['status']);
        $this->assertSame(self::LINE, $request['body']);
        $this->assertSame('Bearer test-token', $request['headers']['Authorization']);
        $this->assertSame('gzip', $request['headers']['Content-Encoding']);
        $this->assertSame('application/x-ndjson', $request['headers']['Content-Type']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertTrue(Uuid::isValid($request['headers']['X-Rocketeers-Batch']));
        $this->assertSame('integration-host', $request['headers']['X-Rocketeers-Server']);
        $this->assertSame('integration', $request['headers']['X-Rocketeers-Agent']);
        $this->assertArrayNotHasKey('Expect', $request['headers']);
    }

    public function test_a_batch_that_failed_is_delivered_once_under_the_same_id()
    {
        file_put_contents($this->directory.'/plan', "503\n");

        $this->startAgent();

        $this->assertSame(Frame::OK, $this->connection()->send([self::LINE]));

        [$failed, $delivered] = $this->waitForRequests(2);

        $this->assertSame(503, $failed['status']);
        $this->assertSame(202, $delivered['status']);
        $this->assertSame($failed['headers']['X-Rocketeers-Batch'], $delivered['headers']['X-Rocketeers-Batch']);
        $this->assertSame(self::LINE, $delivered['body']);

        usleep(1500000);

        $this->assertCount(2, $this->requests());
    }

    public function test_sigterm_removes_the_socket_at_once_and_drains_before_exiting()
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The agent needs ext-pcntl to handle signals.');
        }

        $this->startAgent();

        $this->assertSame(Frame::OK, $this->connection()->send([self::LINE]));

        proc_terminate($this->agent, 15);

        $this->assertSame(0, $this->waitForExit($this->agent), (string) @file_get_contents($this->directory.'/agent.log'));
        $this->assertFileDoesNotExist($this->socket);
        $this->assertSame([self::LINE], array_column($this->requests(), 'body'));
        $this->assertNull($this->connection()->send([self::LINE]));
    }

    protected function connection(): AgentConnection
    {
        return new AgentConnection(new AgentEndpoint('unix://'.$this->socket), IngestKey::fromToken('test-token'));
    }

    protected function startUpstream(): void
    {
        $log = $this->directory.'/upstream.log';

        $this->upstream = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, dirname(__DIR__).'/Fixtures/upstream.php'],
            [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']],
            $pipes,
            null,
            ['ROCKETEERS_UPSTREAM_DIR' => $this->directory] + getenv()
        );

        $this->waitUntil(function () use ($log) {
            return strpos((string) @file_get_contents($log), 'started') !== false;
        }, 'The fake ingest endpoint did not start.');
    }

    protected function startAgent(): void
    {
        $log = $this->directory.'/agent.log';

        $this->agent = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/agent.php', 'unix://'.$this->socket, 'http://127.0.0.1:'.$this->port.'/v1', 'test-token'],
            [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']],
            $pipes
        );

        $this->waitUntil(function () {
            return file_exists($this->socket);
        }, 'The agent did not start listening.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function waitForRequests(int $count): array
    {
        $this->waitUntil(function () use ($count) {
            return count($this->requests()) >= $count;
        }, sprintf('Expected %d uploads.', $count));

        return $this->requests();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function requests(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->directory.'/requests.jsonl')));

        return array_values(array_map(function (string $line) {
            return json_decode($line, true);
        }, $lines));
    }

    /**
     * @param  resource  $process
     */
    protected function waitForExit($process): ?int
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                return $status['exitcode'];
            }

            usleep(20000);
        }

        return null;
    }

    protected function waitUntil(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 10;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail($message.' Agent log: '.@file_get_contents($this->directory.'/agent.log'));
            }

            usleep(20000);
        }
    }

    protected function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        fclose($server);

        return $port;
    }
}
