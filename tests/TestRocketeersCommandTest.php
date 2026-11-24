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

        $mock = $this->mock(Rocketeers::class);
        $mock->shouldReceive('report')->once()->andReturn('"OK"');
        $mock->shouldReceive('lastStatus')->andReturn(200);

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
            ->expectsOutputToContain('Outgoing requests: not recorded, because the "testing" environment is not listed in rocketeers.environments.')
            ->expectsOutputToContain('Rocketeers received the test error (HTTP 200 from https://api.rocketeersapp.com/v1/errors). It is on your dashboard now.')
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

    public function test_it_delivers_through_a_running_agent_when_asked()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->app->instance(AgentConnection::class, $agent = new FakeAgentConnection);
        $client = $this->capturingClient();

        $this->artisan('rocketeers:test', ['--agent' => true])
            ->expectsOutputToContain('Rocketeers agent: running (2.24.0) at unix:///tmp/rocketeers-fake.sock.')
            ->expectsOutputToContain('Test error handed to the Rocketeers agent, which uploads it within seconds.')
            ->expectsOutputToContain('Whether it arrived cannot be shown from here')
            ->expectsOutputToContain('Outgoing requests: recorded for 100% of requests, jobs and commands')
            ->assertExitCode(0);

        $this->assertSame([], $client->posted);
        $this->assertCount(1, $agent->frames);
        $this->assertStringStartsWith('{"t":"error","v":1,', $agent->frames[0][0]);
    }

    public function test_it_posts_directly_even_when_the_agent_runs_so_the_answer_can_be_shown()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->app->instance(AgentConnection::class, $agent = new FakeAgentConnection);
        $client = $this->capturingClient();

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Rocketeers received the test error (HTTP 200')
            ->assertExitCode(0);

        $this->assertCount(1, $client->posted);
        $this->assertSame([], $agent->frames);
        $this->assertFalse(AgentAwareRocketeers::bypassed());
    }

    public function test_the_direct_option_still_works()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $this->app->instance(AgentConnection::class, $agent = new FakeAgentConnection);
        $client = $this->capturingClient();

        $this->artisan('rocketeers:test', ['--direct' => true])
            ->expectsOutputToContain('Rocketeers received the test error (HTTP 200')
            ->assertExitCode(0);

        $this->assertCount(1, $client->posted);
        $this->assertSame([], $agent->frames);
    }

    public function test_it_fails_and_explains_a_rejected_token()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $client = $this->capturingClient();
        $client->status = 401;
        $client->response = '{"message":"Unauthenticated."}';

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('The test error did not arrive: Rocketeers answered HTTP 401 from https://api.rocketeersapp.com/v1/errors.')
            ->expectsOutputToContain('Rocketeers said: Unauthenticated.')
            ->expectsOutputToContain('The API token is not recognised.')
            ->assertExitCode(1);
    }

    public function test_it_fails_and_explains_a_token_that_may_not_report_errors()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $client = $this->capturingClient();
        $client->status = 403;
        $client->response = '{"message":"Error logging requires an environment token."}';

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Rocketeers answered HTTP 403')
            ->expectsOutputToContain('Rocketeers said: Error logging requires an environment token.')
            ->expectsOutputToContain('Use the token of this environment in Rocketeers, not a personal API token.')
            ->assertExitCode(1);
    }

    public function test_it_leaves_out_a_body_that_is_not_json()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $client = $this->capturingClient();
        $client->status = 502;
        $client->response = '<html><body>Bad Gateway</body></html>';

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('Rocketeers answered HTTP 502')
            ->doesntExpectOutputToContain('Rocketeers said:')
            ->expectsOutputToContain('Something went wrong at Rocketeers.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_api_cannot_be_reached()
    {
        config(['rocketeers.api_token' => 'test-token', 'rocketeers.environments' => ['testing']]);

        $client = $this->capturingClient();
        $client->status = null;
        $client->response = false;
        $client->error = 'Could not resolve host: api.rocketeersapp.com';

        $this->artisan('rocketeers:test')
            ->expectsOutputToContain('The test error did not arrive: https://api.rocketeersapp.com/v1/errors could not be reached (Could not resolve host: api.rocketeersapp.com).')
            ->assertExitCode(1);
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
