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

    public function getJobsByStatus(string $status): Collection
    {
        $jobs = new Collection;

        $this->getRecent()->each(function ($job) use ($jobs, $status) {
            if ($job->status === $status) {
                $jobs->push($job);
            }
        });

        return $jobs;
    }

    public function countJobsByStatus(string $status): int
    {
        return $this->getJobsByStatus($status)->count();
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
