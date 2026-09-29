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
