<?php

namespace Rocketeers\Laravel\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\Listeners\LogJobException;
use Rocketeers\Laravel\Logging\RedactLogChannel;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Redactor;
use Rocketeers\Rocketeers;
use RuntimeException;

/** Records what would be sent, through the same redaction gate the real client applies. */
class RecordingClient extends Rocketeers
{
    /** @var array<int, array<string, mixed>> */
    public array $reports = [];

    public function report(array $data)
    {
        $this->reports[] = $this->redactor()->redactPayload($data);

        return 'ok';
    }
}

class LogRedactionTest extends TestCase
{
    protected string $logPath;

    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $this->logPath = sys_get_temp_dir().'/rocketeers-redaction-test.log';

        @unlink($this->logPath);

        $app['config']->set('rocketeers.api_token', 'test-token');
        $app['config']->set('rocketeers.environments', ['testing']);
        $app['config']->set('logging.channels.rocketeers', [
            'driver' => 'rocketeers',
            'level' => 'debug',
        ]);
        $app['config']->set('logging.channels.testfile', [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    protected function recordingClient(): RecordingClient
    {
        $client = (new RecordingClient('test-token'))->setRedactor(app(Redactor::class));

        $this->app->instance('rocketeers.client', $client);

        return $client;
    }

    public function test_it_taps_every_configured_channel_except_the_stack()
    {
        $this->assertContains(RedactLogChannel::class, config('logging.channels.testfile.tap'));
        $this->assertContains(RedactLogChannel::class, config('logging.channels.single.tap'));
        $this->assertNotContains(RedactLogChannel::class, (array) config('logging.channels.stack.tap', []));
    }

    public function test_it_can_be_switched_off()
    {
        config(['rocketeers.redact_logs' => false, 'logging.channels.other' => ['driver' => 'single', 'path' => $this->logPath]]);

        (new RocketeersLoggerServiceProvider($this->app))->boot();

        $this->assertSame([], (array) config('logging.channels.other.tap', []));
    }

    public function test_it_keeps_a_credential_in_the_ambient_context_out_of_the_log_file()
    {
        Context::add('s3_config', ['credentials' => ['secret' => 'super-secret-value']]);

        Log::channel('testfile')->error('storage listing failed', ['current_password' => 'hunter2']);

        $contents = file_get_contents($this->logPath);

        $this->assertStringContainsString('storage listing failed', $contents);
        $this->assertStringNotContainsString('super-secret-value', $contents);
        $this->assertStringNotContainsString('hunter2', $contents);
    }

    public function test_it_redacts_the_request_input_the_handler_reports()
    {
        $client = $this->recordingClient();

        $this->app['request']->merge(['current_password' => 'hunter2', 'email' => 'mark@ux.nl']);

        Log::channel('rocketeers')->error('boom', ['exception' => new RuntimeException('boom')]);

        $this->assertCount(1, $client->reports);
        $this->assertSame('[redacted]', $client->reports[0]['inputs']['current_password']);
        $this->assertSame('mark@ux.nl', $client->reports[0]['inputs']['email']);
    }

    public function test_it_redacts_the_reported_query_string()
    {
        $client = $this->recordingClient();

        $this->app->instance('request', Request::create('https://app.test/auth/github/callback?code=4ab19c&team=3', 'GET'));

        Log::channel('rocketeers')->error('boom', ['exception' => new RuntimeException('boom')]);

        $this->assertSame('[redacted]', $client->reports[0]['querystring']['code']);
        $this->assertSame('3', $client->reports[0]['querystring']['team']);
        $this->assertStringNotContainsString('4ab19c', json_encode($client->reports[0]));
    }

    /**
     * The queue listener reports the job's raw body, which is the serialised payload — the
     * one place a credential travels as JSON inside a string rather than as an array.
     */
    public function test_it_redacts_a_credential_in_a_queued_jobs_raw_body()
    {
        $client = $this->recordingClient();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getConnectionName')->andReturn('redis');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\SyncStorages');
        $job->shouldReceive('getRawBody')->andReturn(json_encode([
            'displayName' => 'App\Jobs\SyncStorages',
            'data' => ['credentials' => ['secret' => 'super-secret-value']],
        ]));

        (new LogJobException($client))->handle(
            new JobExceptionOccurred('redis', $job, new RuntimeException('boom'))
        );

        $this->assertCount(1, $client->reports);
        $this->assertStringNotContainsString('super-secret-value', $client->reports[0]['body']);
        $this->assertStringContainsString('SyncStorages', $client->reports[0]['body']);
    }
}
