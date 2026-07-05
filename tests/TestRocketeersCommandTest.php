<?php

namespace Rocketeers\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Rocketeers;

class TestRocketeersCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
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
}
