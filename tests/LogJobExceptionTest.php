<?php

namespace Rocketeers\Laravel\Tests;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Rocketeers;
use RuntimeException;
use Throwable;

class LogJobExceptionTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['rocketeers.environments' => ['testing']]);
    }

    public function test_an_exception_thrown_in_a_job_is_reported()
    {
        $this->expectReports(1);

        $this->failJobWith(new RuntimeException('boom'));
    }

    public function test_an_exception_marked_shouldnt_report_is_not_reported()
    {
        $this->expectReports(0);

        $this->failJobWith(new UnreportedJobException('the customer app broke'));
    }

    public function test_an_exception_the_handler_ignores_is_not_reported()
    {
        $this->app->make(ExceptionHandler::class)->ignore(RuntimeException::class);

        $this->expectReports(0);

        $this->failJobWith(new RuntimeException('ignored'));
    }

    protected function expectReports(int $times): void
    {
        $client = Mockery::mock(Rocketeers::class);
        $client->shouldReceive('report')->times($times);

        $this->app->instance(Rocketeers::class, $client);
    }

    protected function failJobWith(Throwable $exception): void
    {
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Deploy');

        Event::dispatch(new JobExceptionOccurred('redis', $job, $exception));
    }
}

class UnreportedJobException extends Exception implements ShouldntReport {}
