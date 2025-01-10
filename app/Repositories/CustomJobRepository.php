<?php

namespace App\Repositories;

use App\Contracts\CustomJobRepositoryInterface;
use Illuminate\Support\Collection;
use Laravel\Horizon\Repositories\RedisJobRepository;
use Laravel\Horizon\Repositories\RedisMasterSupervisorRepository;

class CustomJobRepository extends RedisJobRepository implements CustomJobRepositoryInterface
{
    public function getHorizonWorkers()
    {
        $redisMasterSupervisorRepository = app(RedisMasterSupervisorRepository::class);

        return $redisMasterSupervisorRepository->all();
    }

    public function getReservedJobs(): Collection
    {
        return $this->getJobsByStatus('reserved');
    }

    public function getJobStatus(string $jobId): string
    {
        return $this->connection()->get('job:'.$jobId.':status');
    }

    /**
     * Get all jobs with a specific status.
     */
    public function getJobsByStatus(string $status, ?string $worker = null): Collection
    {
        $jobs = new Collection;

        $this->getRecent()->each(function ($job) use ($jobs, $status, $worker) {
            if ($job->status === $status) {
                if ($worker) {
                    if ($job->worker !== $worker) {
                        return;
                    }
                }
                $jobs->push($job);
            }
        });

        return $jobs;
    }

    /**
     * Get the count of jobs with a specific status.
     */
    public function countJobsByStatus(string $status): int
    {
        return $this->getJobsByStatus($status)->count();
    }

    /**
     * Get jobs that have been running longer than a specified duration in seconds.
     */
    public function getLongRunningJobs(int $seconds): Collection
    {
        $jobs = new Collection;

        $this->getRecent()->each(function ($job) use ($jobs, $seconds) {
            if ($job->status === 'reserved' &&
                isset($job->reserved_at) &&
                (time() - strtotime($job->reserved_at)) > $seconds) {
                $jobs->push($job);
            }
        });

        return $jobs;
    }

    public function getQueues(): array
    {
        $queues = $this->connection()->keys('queue:*');
        $queues = array_map(function ($queue) {
            return explode(':', $queue)[2];
        }, $queues);

        return $queues;
    }
}
