<?php

namespace Rocketeers\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

class HorizonStatsController
{
    /**
     * Return the aggregate queue stats the Rocketeers dashboard renders.
     *
     * This deliberately mirrors a whitelisted subset of Horizon's own
     * DashboardStatsController rather than proxying /horizon/api/*, so a leaked
     * signature can never reach job payloads or the retry endpoints.
     */
    public function __invoke(): JsonResponse
    {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            return new JsonResponse(['message' => 'Horizon is not installed.'], 404);
        }

        return new JsonResponse([
            'status' => $this->status(),
            'processes' => $this->processes(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ]);
    }

    /**
     * inactive when no master is running, paused when every master is paused.
     */
    protected function status(): string
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 'inactive';
        }

        return collect($masters)->every(fn ($master) => $master->status === 'paused')
            ? 'paused'
            : 'running';
    }

    /**
     * Total process count across every supervisor.
     */
    protected function processes(): int
    {
        return collect(app(SupervisorRepository::class)->all())
            ->reduce(fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0);
    }
}
