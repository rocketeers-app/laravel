<?php

namespace Rocketeers\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Laravel\Tests\Support\FakeAgentConnection;
use Rocketeers\Laravel\Tests\Support\PostCapturingRocketeers;
use Rocketeers\Redactor;
use Rocketeers\Rocketeers;

class TestRocketeersCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function tearDown(): void
    {
        AgentAwareRocketeers::bypass(false);

        parent::tearDown();
    }

    public function test_it_reports_a_test_error_when_a_token_is_configured()
    {
        config(['rocketeers.api_token' => 'test-token']);

        $this->mock(Rocketeers::class)
            ->shouldReceive('report')
            ->once();

        $this->artisan('rocketeers:test')->assertExitCode(0);
    }

    public function test_it_fails_when_no_token_is_configured()
    {
        config(['rocketeers.api_token' => null]);

        $this->mock(Rocketeers::class)
            ->shouldReceive('report')
            ->never();

        $this->artisan('rocketeers:test')->assertExitCode(1);
    }

    public function test_without_an_agent_it_sends_directly_and_says_why_nothing_else_is_recorded()
    {
        $socket = '/tmp/rkt-missing-'.bin2hex(random_bytes(4)).'/rocketeers.sock';

        config(['rocketeers.api_token' => 'test-token', 'rocketeers.ingest.uri' => 'unix://'.$socket]);

        $client = $this->capturingClient();

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Testing Rocketeers error reporting...')
            ->expectsOutputToContain('Rocketeers agent: not running at unix://'.$socket)
            ->expectsOutputToContain('Test error sent to Rocketeers. Check your dashboard to confirm it arrived.')
            ->expectsOutputToContain('Sent directly to https://api.rocketeersapp.com/v1/errors.')
            ->expectsOutputToContain('Outgoing requests: not recorded, because the "testing" environment is not listed in rocketeers.environments.')
            ->assertExitCode(0);

        $this->assertCount(1, $client->posted);
        $this->assertArrayNotHasKey('report_id', json_decode($client->posted[0], true));
    }

    public function test_it_explains_a_socket_path_that_is_too_long()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->capturingClient();

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('more than the 100 a unix socket allows. Set ROCKETEERS_INGEST_URI to a shorter unix:// path')
            ->expectsOutputToContain('Outgoing requests: not recorded, because the Rocketeers agent is not running.')
            ->assertExitCode(0);
    }

    public function test_it_delivers_through_a_running_agent()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->app->instance(AgentConnection::class, $agent = new FakeAgentConnection);
        $client = $this->capturingClient();

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Rocketeers agent: running (2.24.0) at unix:///tmp/rocketeers-fake.sock.')
            ->expectsOutputToContain('Delivered through the Rocketeers agent')
            ->expectsOutputToContain('Outgoing requests: recorded for 100% of requests, jobs and commands')
            ->assertExitCode(0);

        $this->assertSame([], $client->posted);
        $this->assertCount(1, $agent->frames);
        $this->assertStringStartsWith('{"t":"error","v":1,', $agent->frames[0][0]);
    }

    public function test_the_direct_option_skips_a_running_agent()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->app->instance(AgentConnection::class, $agent = new FakeAgentConnection);
        $client = $this->capturingClient();

        $this->artisan('rocketeers:test', ['--direct' => true])
            ->expectsOutputToContain('Sent directly to https://api.rocketeersapp.com/v1/errors.')
            ->assertExitCode(0);

        $this->assertCount(1, $client->posted);
        $this->assertSame([], $agent->frames);
        $this->assertFalse(AgentAwareRocketeers::bypassed());
    }

    public function test_it_says_when_outgoing_requests_are_switched_off()
    {
        config([
            'rocketeers.api_token' => 'test-token',
            'rocketeers.environments' => ['testing'],
            'rocketeers.ingest.outgoing_requests.enabled' => false,
        ]);

        $this->app->instance(AgentConnection::class, new FakeAgentConnection);
        $this->capturingClient();

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Outgoing requests: not recorded, because they are switched off (ROCKETEERS_OUTGOING_REQUESTS=false).')
            ->assertExitCode(0);
    }

    protected function capturingClient(): PostCapturingRocketeers
    {
        $client = (new PostCapturingRocketeers('test-token', $this->app))->setRedactor($this->app->make(Redactor::class));

        $this->app->instance('rocketeers.client', $client);

        return $client;
    }
}
