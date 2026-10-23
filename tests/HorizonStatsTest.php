<?php

namespace Rocketeers\Laravel\Tests;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Laravel\Support\HorizonSignature;

class HorizonStatsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('rocketeers.horizon.secret', 'shared-secret');
        $app['config']->set('rocketeers.horizon.origin', 'https://app.rocketeersapp.com');
    }

    protected function url(int $expires, ?string $signature = null): string
    {
        return '/'.HorizonSignature::PATH.'?'.http_build_query([
            'expires' => $expires,
            'signature' => $signature ?? HorizonSignature::sign($expires, 'shared-secret'),
        ]);
    }

    protected function fakeHorizon(): void
    {
        $master = new \stdClass;
        $master->status = 'running';

        $supervisor = new \stdClass;
        $supervisor->processes = ['default' => 3];

        $this->mock(MasterSupervisorRepository::class)
            ->shouldReceive('all')->andReturn([$master]);

        $this->mock(SupervisorRepository::class)
            ->shouldReceive('all')->andReturn([$supervisor]);

        $this->mock(MetricsRepository::class)
            ->shouldReceive('jobsProcessedPerMinute')->andReturn(12)
            ->shouldReceive('measuredQueues')->andReturn(['default', 'emails'])
            ->shouldReceive('throughputForQueue')->with('default')->andReturn(30)
            ->shouldReceive('throughputForQueue')->with('emails')->andReturn(6)
            ->shouldReceive('runtimeForQueue')->with('default')->andReturn(250.0)
            ->shouldReceive('runtimeForQueue')->with('emails')->andReturn(1200.0);

        $this->mock(WorkloadRepository::class)
            ->shouldReceive('get')->andReturn([
                ['name' => 'default', 'length' => 40, 'wait' => 7, 'processes' => 3, 'split_queues' => null],
            ]);

        $this->mock(JobRepository::class)
            ->shouldReceive('countRecentlyFailed')->andReturn(2)
            ->shouldReceive('countRecent')->andReturn(500);

        $this->mock(WaitTimeCalculator::class)
            ->shouldReceive('calculate')->andReturn(['redis:default' => 7]);
    }

    public function test_it_returns_stats_for_a_valid_signature()
    {
        $this->fakeHorizon();

        $this->get($this->url(time() + 300))
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'processes' => 3,
                'jobsPerMinute' => 12,
                'failedJobs' => 2,
                'recentJobs' => 500,
            ])
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.rocketeersapp.com');
    }

    public function test_it_never_exposes_job_payloads()
    {
        $this->fakeHorizon();

        $keys = array_keys($this->get($this->url(time() + 300))->json());

        $this->assertEqualsCanonicalizing(
            ['status', 'processes', 'jobsPerMinute', 'failedJobs', 'recentJobs', 'wait', 'queues'],
            $keys
        );
    }

    public function test_it_reports_the_workload_of_each_queue()
    {
        $this->fakeHorizon();

        $queues = collect($this->get($this->url(time() + 300))->json('queues'))->keyBy('name');

        $this->assertSame(['name' => 'default', 'length' => 40, 'wait' => 7, 'processes' => 3, 'throughput' => 30, 'runtime' => 250], $queues['default']);
        $this->assertSame('emails', $queues['emails']['name']);
        $this->assertNull($queues['emails']['wait']);
        $this->assertNull($queues['emails']['processes']);
        $this->assertSame(1200, $queues['emails']['runtime']);
    }

    public function test_it_rejects_an_expired_signature()
    {
        $this->get($this->url(time() - 1))->assertForbidden();
    }

    public function test_it_rejects_a_tampered_signature()
    {
        $expires = time() + 300;

        $this->get($this->url($expires, HorizonSignature::sign($expires + 1, 'shared-secret')))
            ->assertForbidden();
    }

    public function test_it_rejects_a_signature_from_a_different_secret()
    {
        $expires = time() + 300;

        $this->get($this->url($expires, HorizonSignature::sign($expires, 'other-secret')))
            ->assertForbidden();
    }

    public function test_it_stays_closed_when_no_secret_is_configured()
    {
        config(['rocketeers.horizon.secret' => null]);

        $this->get($this->url(time() + 300))->assertForbidden();
    }

    public function test_it_rejects_a_request_with_no_signature_at_all()
    {
        $this->get('/'.HorizonSignature::PATH)->assertForbidden();
    }
}
