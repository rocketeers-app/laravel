<?php

namespace Rocketeers\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Redactor;
use Rocketeers\Rocketeers;
use RuntimeException;

class LogChannelTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function registerProviderAgain(): void
    {
        (new RocketeersLoggerServiceProvider($this->app))->register();
    }

    public function test_it_registers_the_rocketeers_channel()
    {
        $this->assertSame('rocketeers', config('logging.channels.rocketeers.driver'));
        $this->assertSame('debug', config('logging.channels.rocketeers.level'));
    }

    public function test_it_adds_the_rocketeers_channel_to_the_default_stack()
    {
        $this->assertSame(['single', 'rocketeers'], config('logging.channels.stack.channels'));
    }

    public function test_an_exception_logged_on_the_default_channel_is_reported()
    {
        config(['rocketeers.environments' => ['testing']]);

        $client = Mockery::mock(Rocketeers::class);
        $client->shouldReceive('redactor')->andReturn(app(Redactor::class));
        $client->shouldReceive('report')->once();

        $this->app->instance('rocketeers.client', $client);

        Log::error('boom', ['exception' => new RuntimeException('boom')]);
    }

    public function test_a_string_exception_is_reported_with_the_message_from_its_context()
    {
        $report = $this->reportOf('Skipped a field', [
            'exception' => 'Error',
            'message' => 'Call to a member function hasRole() on null',
            'component' => 'ActiveEmployeeSelect',
        ]);

        $this->assertSame('Error', $report['exception']);
        $this->assertSame('Call to a member function hasRole() on null', $report['message']);
        $this->assertNull($report['file']);
        $this->assertNull($report['line']);
        $this->assertNull($report['trace']);
        $this->assertSame('ActiveEmployeeSelect', $report['context']['component']);
    }

    public function test_a_string_exception_without_a_context_message_is_reported_with_the_log_message()
    {
        $report = $this->reportOf('Skipped a field', ['exception' => 'Error']);

        $this->assertSame('Error', $report['exception']);
        $this->assertSame('Skipped a field', $report['message']);
    }

    public function test_a_throwable_is_reported_with_its_own_message_file_and_line()
    {
        $exception = new RuntimeException('boom');

        $report = $this->reportOf('Something failed', ['exception' => $exception, 'message' => 'ignored']);

        $this->assertSame(RuntimeException::class, $report['exception']);
        $this->assertSame('boom', $report['message']);
        $this->assertSame(__FILE__, $report['file']);
        $this->assertSame((string) $exception->getLine(), $report['line']);
        $this->assertIsArray($report['trace']);
    }

    protected function reportOf(string $message, array $context): array
    {
        config(['rocketeers.environments' => ['testing']]);

        $reported = null;

        $client = Mockery::mock(Rocketeers::class);
        $client->shouldReceive('redactor')->andReturn(app(Redactor::class));
        $client->shouldReceive('report')->once()->andReturnUsing(function (array $report) use (&$reported) {
            $reported = $report;
        });

        $this->app->instance('rocketeers.client', $client);

        Log::error($message, $context);

        return $reported;
    }

    public function test_it_adds_the_rocketeers_channel_only_once()
    {
        $this->registerProviderAgain();

        $this->assertSame(['single', 'rocketeers'], config('logging.channels.stack.channels'));
    }

    public function test_it_keeps_a_rocketeers_channel_the_app_configured_itself()
    {
        config(['logging.channels.rocketeers' => ['driver' => 'rocketeers', 'level' => 'error']]);

        $this->registerProviderAgain();

        $this->assertSame('error', config('logging.channels.rocketeers.level'));
    }

    public function test_it_leaves_a_default_channel_that_is_not_a_stack_alone()
    {
        config(['logging.default' => 'single']);

        $this->registerProviderAgain();

        $this->assertNull(config('logging.channels.single.channels'));
    }
}
