<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\ExecutionContext;
use Rocketeers\Laravel\Ingest\Frame;
use Rocketeers\Laravel\Ingest\RecordBuffer;
use Rocketeers\Laravel\Ingest\Uuid;
use Rocketeers\Laravel\Tests\Support\PostCapturingRocketeers;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;
use Rocketeers\Redactor;
use Rocketeers\Rocketeers;

class AgentAwareRocketeersTest extends IngestTestCase
{
    protected array $report = [
        'environment' => 'production',
        'code' => 500,
        'exception' => 'RuntimeException',
        'message' => 'boom',
        'inputs' => ['email' => 'mark@ux.nl', 'current_password' => 'hunter2'],
        'url' => 'https://app.test/callback?code=4ab19c',
    ];

    public function test_the_package_reports_through_the_agent_aware_client()
    {
        $this->assertInstanceOf(AgentAwareRocketeers::class, $this->app->make('rocketeers.client'));
        $this->assertSame($this->app->make('rocketeers.client'), $this->app->make(Rocketeers::class));
    }

    public function test_without_an_agent_it_posts_exactly_what_the_plain_client_posts()
    {
        $directory = TemporaryDirectory::create();

        try {
            $this->useAgent(new AgentConnection(new AgentEndpoint('unix://'.$directory.'/rocketeers.sock'), 'key'));

            $client = $this->client();
            $client->report($this->report);

            $this->assertSame([$this->plainJson($this->report)], $client->posted);
            $this->assertStringNotContainsString('report_id', $client->posted[0]);
            $this->assertSame(AgentAwareRocketeers::DIRECT, $client->lastTransport());
            $this->assertSame(['.', '..'], scandir($directory));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public function test_it_posts_like_the_plain_client_when_ingest_is_disabled()
    {
        config(['rocketeers.ingest.enabled' => false]);

        $client = $this->client();
        $client->report($this->report);

        $this->assertSame([$this->plainJson($this->report)], $client->posted);
        $this->assertSame([], $this->agent->frames);
    }

    public function test_it_posts_like_the_plain_client_when_bypassed()
    {
        AgentAwareRocketeers::bypass(true);

        $client = $this->client();
        $client->report($this->report);

        $this->assertSame([$this->plainJson($this->report)], $client->posted);
        $this->assertSame([], $this->agent->frames);
    }

    public function test_it_hands_the_error_and_the_buffered_requests_to_the_agent_in_one_frame()
    {
        $buffer = $this->app->make(RecordBuffer::class);
        $buffer->push('{"t":"outgoing_request","v":1,"path":"/a"}');
        $buffer->push('{"t":"outgoing_request","v":1,"path":"/b"}');

        $client = $this->client();

        $this->assertSame(Frame::OK, $client->report($this->report));
        $this->assertSame([], $client->posted);
        $this->assertSame(AgentAwareRocketeers::AGENT, $client->lastTransport());
        $this->assertCount(1, $this->agent->frames);

        [$error, $first, $second] = $this->agent->frames[0];

        $this->assertStringStartsWith('{"t":"error","v":1,"trace_id":"', $error);
        $this->assertSame('{"t":"outgoing_request","v":1,"path":"/a"}', $first);
        $this->assertSame('{"t":"outgoing_request","v":1,"path":"/b"}', $second);
        $this->assertSame(0, $buffer->count());

        $line = json_decode($error, true);

        $this->assertSame('error', $line['t']);
        $this->assertSame(1, $line['v']);
        $this->assertSame($this->app->make(ExecutionContext::class)->traceId(), $line['trace_id']);
        $this->assertTrue(Uuid::isValid($line['payload']['report_id']));
        $this->assertSame('[redacted]', $line['payload']['inputs']['current_password']);
        $this->assertStringNotContainsString('hunter2', $error);
        $this->assertStringNotContainsString('4ab19c', $error);
        $this->assertSame(
            $this->plainJson($this->report + ['report_id' => $line['payload']['report_id']]),
            substr($error, strpos($error, '"payload":') + 10, -1)
        );
    }

    public function test_a_refused_frame_is_posted_directly_with_the_same_report_id()
    {
        $this->agent->reply = Frame::ERR_FULL;

        $client = $this->client();
        $client->report($this->report);

        $sent = json_decode($this->agent->frames[0][0], true)['payload'];
        $posted = json_decode($client->posted[0], true);

        $this->assertCount(1, $client->posted);
        $this->assertSame($sent['report_id'], $posted['report_id']);
        $this->assertSame($sent, $posted);
        $this->assertSame(AgentAwareRocketeers::DIRECT, $client->lastTransport());
    }

    public function test_an_agent_that_does_not_answer_means_a_direct_post()
    {
        $this->agent->reply = null;

        $client = $this->client();
        $client->report($this->report);

        $this->assertCount(1, $client->posted);
        $this->assertArrayHasKey('report_id', json_decode($client->posted[0], true));
    }

    public function test_a_frame_over_4_mib_is_posted_directly_without_connecting()
    {
        $directory = TemporaryDirectory::create();
        touch($directory.'/rocketeers.sock');

        try {
            $this->useAgent($connection = new AgentConnection(new AgentEndpoint('unix://'.$directory.'/rocketeers.sock'), 'key'));

            $client = $this->client();
            $client->report(['message' => str_repeat('x', Frame::MAX_BYTES)]);

            $this->assertCount(1, $client->posted);
            $this->assertArrayHasKey('report_id', json_decode($client->posted[0], true));
            $this->assertTrue($connection->present(), 'An oversized frame says nothing about the agent being there.');
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public function test_the_api_url_is_only_overridden_when_configured()
    {
        $this->app->make('rocketeers.client');

        $this->assertSame('https://api.rocketeersapp.com/v1', Rocketeers::baseUrl());

        config(['rocketeers.api_url' => 'https://rocketeers.test/api/v1/']);
        $this->app->forgetInstance('rocketeers.client');
        $this->app->make('rocketeers.client');

        $this->assertSame('https://rocketeers.test/api/v1', Rocketeers::baseUrl());
    }

    protected function client(): PostCapturingRocketeers
    {
        return (new PostCapturingRocketeers('test-token', $this->app))->setRedactor($this->app->make(Redactor::class));
    }

    protected function plainJson(array $report): string
    {
        return (new Rocketeers('test-token'))->setRedactor($this->app->make(Redactor::class))->encodeReport($report);
    }
}
