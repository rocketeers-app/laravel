<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\ExecutionContext;
use Rocketeers\Laravel\Ingest\RecordBuffer;
use Rocketeers\Laravel\Ingest\RecordOutgoingRequests;
use Rocketeers\Laravel\Ingest\Uuid;
use Rocketeers\Laravel\RocketeersIngestServiceProvider;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;
use RuntimeException;

class RecordOutgoingRequestsTest extends IngestTestCase
{
    public function test_it_records_the_metadata_of_a_request()
    {
        $this->app->make(ExecutionContext::class)->start(ExecutionContext::JOB, 'App\Jobs\SyncOrders');

        $this->http()->fake(['*' => Factory::response('{"id":1}', 201)]);

        $this->http()->post('https://API.example.com/v1/orders/abcdefghijklmnopq1234/items', ['sku' => 'A1']);

        $records = $this->buffered();

        $this->assertCount(1, $records);
        $this->assertSame([
            't', 'v', 'trace_id', 'timestamp', 'method', 'scheme', 'host', 'port', 'path', 'status', 'duration_ms',
            'request_size', 'response_size', 'error', 'execution_type', 'execution_name',
        ], array_keys($records[0]));

        $record = $records[0];

        $this->assertSame('outgoing_request', $record['t']);
        $this->assertSame(1, $record['v']);
        $this->assertSame($this->app->make(ExecutionContext::class)->traceId(), $record['trace_id']);
        $this->assertTrue(Uuid::isValid($record['trace_id']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $record['timestamp']);
        $this->assertEqualsWithDelta(time(), strtotime($record['timestamp']), 5);
        $this->assertSame('POST', $record['method']);
        $this->assertSame('https', $record['scheme']);
        $this->assertSame('api.example.com', $record['host']);
        $this->assertNull($record['port']);
        $this->assertSame('/v1/orders/*****/items', $record['path']);
        $this->assertSame(201, $record['status']);
        $this->assertIsFloat($record['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $record['duration_ms']);
        $this->assertSame(strlen('{"sku":"A1"}'), $record['request_size']);
        $this->assertSame(strlen('{"id":1}'), $record['response_size']);
        $this->assertNull($record['error']);
        $this->assertSame('job', $record['execution_type']);
        $this->assertSame('App\Jobs\SyncOrders', $record['execution_name']);
    }

    public function test_the_type_is_encoded_first_and_the_duration_always_as_a_float()
    {
        $this->http()->fake();

        $this->http()->get('https://api.example.com/');

        $line = $this->app->make(RecordBuffer::class)->pull()[0];

        $this->assertStringStartsWith('{"t":"outgoing_request","v":1,', $line);
        $this->assertMatchesRegularExpression('/"duration_ms":\d+\.\d+,/', $line);
    }

    public function test_query_strings_headers_bodies_and_userinfo_never_reach_the_record()
    {
        $this->http()->fake(['*' => Factory::response('{"token":"response-body-secret"}', 200, ['X-Session' => 'response-header-secret'])]);

        $this->http()
            ->withHeaders(['Authorization' => 'Bearer header-secret', 'X-Api-Key' => 'other-header-secret'])
            ->withCookies(['session' => 'cookie-secret'], 'api.example.com')
            ->post('https://user:userinfo-secret@api.example.com/v1/login?api_key=query-secret&page=2#fragment-secret', [
                'password' => 'body-secret',
            ]);

        $line = $this->app->make(RecordBuffer::class)->pull()[0];

        foreach (['response-body-secret', 'response-header-secret', 'header-secret', 'cookie-secret', 'userinfo-secret', 'user:', 'query-secret', 'api_key', 'page=2', 'fragment-secret', 'body-secret', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }

        $this->assertSame('/v1/login', json_decode($line, true)['path']);
    }

    public function test_an_error_response_is_recorded_with_its_status()
    {
        $this->http()->fake(['*' => Factory::response('Server Error', 503)]);

        $this->http()->get('https://api.example.com/status');

        $record = $this->buffered()[0];

        $this->assertSame(503, $record['status']);
        $this->assertNull($record['error']);
        $this->assertSame(12, $record['response_size']);
    }

    public function test_a_timeout_is_recorded_without_a_status_and_still_thrown()
    {
        $this->http()->fake(['*' => Factory::failedConnection('cURL error 28: Operation timed out after 1001 milliseconds with 0 bytes received')]);

        try {
            $this->http()->timeout(1)->get('https://slow.example.com/v1/ping');

            $this->fail('The timeout was swallowed.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('cURL error 28', $e->getMessage());
        }

        $record = $this->buffered()[0];

        $this->assertSame('timeout', $record['error']);
        $this->assertNull($record['status']);
        $this->assertNull($record['response_size']);
        $this->assertSame('slow.example.com', $record['host']);
    }

    public function test_the_rejection_is_passed_on_untouched()
    {
        $exception = new ConnectException('cURL error 7: Failed to connect to 10.0.0.1 port 443', new PsrRequest('GET', 'https://10.0.0.1/'));
        $stack = HandlerStack::create(new MockHandler([$exception]));
        $stack->push($this->app->make(RecordOutgoingRequests::class));

        try {
            (new Client(['handler' => $stack]))->get('https://10.0.0.1/');

            $this->fail('The connect failure was swallowed.');
        } catch (ConnectException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertSame('connect', $this->buffered()[0]['error']);
    }

    public function test_a_rejection_carrying_a_response_records_its_status()
    {
        $request = new PsrRequest('GET', 'https://api.example.com/export');
        $response = new PsrResponse(200, [], 'partial');
        $exception = class_exists(ResponseException::class)
            ? new ResponseException('cURL error 28: Operation timed out after 15000 milliseconds', $request, $response)
            : new RequestException('cURL error 28: Operation timed out after 15000 milliseconds', $request, $response);

        $this->http()->fake(['*' => function () use ($exception) {
            return Create::rejectionFor($exception);
        }]);

        try {
            $this->http()->get('https://api.example.com/export');
        } catch (\Throwable $e) {
            //
        }

        $record = $this->buffered()[0];

        $this->assertSame(200, $record['status']);
        $this->assertSame('timeout', $record['error']);
    }

    public function test_curl_errors_are_named()
    {
        $request = new PsrRequest('GET', 'https://api.example.com/');

        $this->assertSame('timeout', RecordOutgoingRequests::error(new ConnectException('cURL error 28: Connection timed out after 5001 milliseconds', $request)));
        $this->assertSame('dns', RecordOutgoingRequests::error(new ConnectException('cURL error 6: Could not resolve host: api.example.com', $request)));
        $this->assertSame('connect', RecordOutgoingRequests::error(new ConnectException('cURL error 7: Failed to connect', $request)));
        $this->assertSame('tls', RecordOutgoingRequests::error(new ConnectException('cURL error 60: SSL certificate problem: unable to get local issuer certificate', $request)));
        $this->assertSame('tls', RecordOutgoingRequests::error(new ConnectException('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', $request)));
        $this->assertSame('other', RecordOutgoingRequests::error(new ConnectException('cURL error 52: Empty reply from server', $request)));
        $this->assertSame('connect', RecordOutgoingRequests::error(new ConnectException('Connection refused for URI https://api.example.com/', $request)));
        $this->assertSame('other', RecordOutgoingRequests::error(new \RuntimeException('Something else')));
        $this->assertSame('other', RecordOutgoingRequests::error('not even an exception'));
    }

    public function test_a_port_is_only_recorded_when_it_is_not_the_default()
    {
        $this->http()->fake();

        $this->http()->get('https://api.example.com:8443/a');
        $this->http()->get('http://api.example.com:80/b');
        $this->http()->get('https://api.example.com:443/c');

        $this->assertSame([8443, null, null], array_column($this->buffered(), 'port'));
    }

    public function test_a_request_is_named_after_its_route()
    {
        $route = (new Route('POST', 'orders/{order}', function () {
            //
        }))->name('orders.store');

        $request = Request::create('/orders/42', 'POST');
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $this->app->instance('request', $request);
        $this->app->make(ExecutionContext::class)->start(ExecutionContext::REQUEST);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $record = $this->buffered()[0];

        $this->assertSame('request', $record['execution_type']);
        $this->assertSame('orders.store', $record['execution_name']);
    }

    public function test_an_unnamed_or_cached_route_is_named_after_its_uri()
    {
        $route = (new Route('POST', 'orders/{order}', function () {
            //
        }))->name('generated::aBcD1234');

        $request = Request::create('/orders/42', 'POST');
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $this->app->instance('request', $request);
        $this->app->make(ExecutionContext::class)->start(ExecutionContext::REQUEST);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $this->assertSame('orders/{order}', $this->buffered()[0]['execution_name']);
    }

    public function test_nothing_is_recorded_for_an_execution_that_was_not_sampled()
    {
        $this->bootWith(['rocketeers.ingest.outgoing_requests.sample_rate' => 0.0]);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $this->assertSame([], $this->buffered());
        $this->assertSame([], $this->agent->frames);
    }

    public function test_nothing_is_recorded_without_a_token()
    {
        $this->bootWith(['rocketeers.api_token' => null]);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $this->assertSame([], $this->http()->getGlobalMiddleware());
        $this->assertSame([], $this->buffered());
    }

    public function test_nothing_is_recorded_in_an_environment_that_does_not_report()
    {
        $this->bootWith(['rocketeers.environments' => ['production']]);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $this->assertSame([], $this->http()->getGlobalMiddleware());
        $this->assertSame([], $this->buffered());
    }

    public function test_nothing_is_recorded_when_switched_off()
    {
        $this->bootWith(['rocketeers.ingest.outgoing_requests.enabled' => false]);

        $this->http()->fake();
        $this->http()->get('https://api.example.com/');

        $this->assertSame([], $this->http()->getGlobalMiddleware());
        $this->assertSame([], $this->buffered());
    }

    public function test_nothing_is_recorded_or_written_without_an_agent()
    {
        $directory = TemporaryDirectory::create();

        try {
            $this->useAgent(new AgentConnection(new AgentEndpoint('unix://'.$directory.'/rocketeers.sock'), 'key'));

            $this->http()->fake();
            $this->http()->get('https://api.example.com/');
            $this->app->terminate();

            $this->assertSame([], $this->buffered());
            $this->assertSame(['.', '..'], scandir($directory));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public function test_a_request_opted_out_with_the_macro_is_not_recorded()
    {
        Http::fake();

        Http::withoutRecording()->get('https://probe.example.com/');
        Http::withHeaders(['Accept' => 'text/html'])->withoutRecording()->get('https://probe.example.com/chained');
        Http::get('https://api.example.com/recorded');

        $records = $this->buffered();

        $this->assertCount(1, $records);
        $this->assertSame('api.example.com', $records[0]['host']);
    }

    public function test_the_opt_out_survives_a_redirect()
    {
        Http::fake([
            'https://old.example.com/*' => Http::response('', 301, ['Location' => 'https://new.example.com/landing']),
            'https://new.example.com/*' => Http::response('landed'),
        ]);

        $response = Http::withoutRecording()->get('https://old.example.com/start');

        $this->assertSame('landed', $response->body());
        $this->assertSame([], $this->buffered());

        Http::get('https://old.example.com/start');

        $this->assertSame(['old.example.com', 'new.example.com'], array_column($this->buffered(), 'host'));
    }

    public function test_nothing_sent_inside_without_recording_is_recorded()
    {
        Http::fake();

        $result = RecordOutgoingRequests::withoutRecording(function () {
            Http::get('https://probe.example.com/outer');

            RecordOutgoingRequests::withoutRecording(function () {
                Http::get('https://probe.example.com/inner');
            });

            $this->assertTrue(RecordOutgoingRequests::paused());

            Http::get('https://probe.example.com/after-inner');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse(RecordOutgoingRequests::paused());

        Http::get('https://api.example.com/recorded');

        $this->assertSame(['api.example.com'], array_column($this->buffered(), 'host'));
    }

    public function test_recording_resumes_after_an_exception_inside_without_recording()
    {
        Http::fake();

        try {
            RecordOutgoingRequests::withoutRecording(function () {
                throw new RuntimeException('Probe failed');
            });

            $this->fail('The exception was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Probe failed', $e->getMessage());
        }

        $this->assertFalse(RecordOutgoingRequests::paused());

        Http::get('https://api.example.com/recorded');

        $this->assertCount(1, $this->buffered());
    }

    public function test_the_macro_is_available_and_harmless_when_ingest_is_inactive()
    {
        $this->bootWith(['rocketeers.api_token' => null]);

        $this->assertFalse(RocketeersIngestServiceProvider::active());
        $this->assertTrue(PendingRequest::hasMacro('withoutRecording'));

        Http::fake(['*' => Http::response('ok')]);

        $this->assertSame('ok', Http::withoutRecording()->get('https://probe.example.com/')->body());
        $this->assertSame([], $this->buffered());
    }
}
