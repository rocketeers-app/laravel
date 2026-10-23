<?php

namespace Rocketeers\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

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
            'queues' => $this->queues(),
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

    /** @return array<int, array{name: string, length: int|null, wait: int|null, processes: int|null, throughput: float, runtime: float}> */
    protected function queues(): array
    {
        $metrics = app(MetricsRepository::class);
        $minutes = $this->minutesSinceSnapshot($metrics);

        $workload = $this->workload();

        return collect($metrics->measuredQueues())
            ->merge($workload->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $queue): array => [
                'name' => $queue,
                'length' => $workload[$queue]['length'] ?? $this->length($queue),
                'wait' => $workload[$queue]['wait'] ?? null,
                'processes' => $workload[$queue]['processes'] ?? null,
                'throughput' => round($metrics->throughputForQueue($queue) / $minutes, 1),
                'runtime' => round((float) $metrics->runtimeForQueue($queue), 1),
            ])
            ->all();
    }

    /** @return Collection<string, array{length: int, wait: int, processes: int}> */
    protected function workload(): Collection
    {
        if (! interface_exists(WorkloadRepository::class)) {
            return collect();
        }

        return collect(app(WorkloadRepository::class)->get())
            ->flatMap(fn (array $queue): array => empty($queue['split_queues'])
                ? [$queue['name'] => $queue]
                : collect($queue['split_queues'])
                    ->mapWithKeys(fn (array $split): array => [$split['name'] => [...$split, 'processes' => $queue['processes']]])
                    ->all())
            ->map(fn (array $queue): array => [
                'length' => (int) $queue['length'],
                'wait' => (int) $queue['wait'],
                'processes' => (int) ($queue['processes'] ?? 0),
            ]);
    }

    protected function length(string $queue): ?int
    {
        try {
            $connection = Queue::connection();

            return (int) (method_exists($connection, 'readyNow') ? $connection->readyNow($queue) : $connection->size($queue));
        } catch (Throwable) {
            return null;
        }
    }

    protected function minutesSinceSnapshot(MetricsRepository $metrics): float
    {
        if (! method_exists($metrics, 'connection')) {
            return 1.0;
        }

        $lastSnapshotAt = (int) $metrics->connection()->get('last_snapshot_at');

        return $lastSnapshotAt > 0 ? max((time() - $lastSnapshotAt) / 60, 1) : 1.0;
    }
}
