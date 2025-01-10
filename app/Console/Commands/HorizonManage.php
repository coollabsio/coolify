<?php

namespace App\Console\Commands;

use App\Repositories\CustomJobRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Repositories\RedisJobRepository;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class HorizonManage extends Command
{
    protected $signature = 'horizon:manage';

    protected $description = 'Manage horizon';

    public function handle()
    {
        $action = select(
            label: 'What to do?',
            options: [
                'pending' => 'Pending Jobs',
                'running' => 'Running Jobs',
                'workers' => 'Workers',
                'failed' => 'Failed Jobs',
                'failed-delete' => 'Failed Jobs - Delete',
                'purge-queues' => 'Purge Queues',
            ]
        );

        if ($action === 'pending') {
            $pendingJobs = app(JobRepository::class)->getPending();
            $pendingJobsTable = [];
            if (count($pendingJobs) === 0) {
                $this->info('No pending jobs found.');

                return;
            }
            foreach ($pendingJobs as $pendingJob) {
                $pendingJobsTable[] = [
                    'id' => $pendingJob->id,
                    'name' => $pendingJob->name,
                    'status' => $pendingJob->status,
                    'reserved_at' => $pendingJob->reserved_at ? now()->parse($pendingJob->reserved_at)->format('Y-m-d H:i:s') : null,
                ];
            }
            table($pendingJobsTable);
        }

        if ($action === 'failed') {
            $failedJobs = app(JobRepository::class)->getFailed();
            $failedJobsTable = [];
            if (count($failedJobs) === 0) {
                $this->info('No failed jobs found.');

                return;
            }
            foreach ($failedJobs as $failedJob) {
                $failedJobsTable[] = [
                    'id' => $failedJob->id,
                    'name' => $failedJob->name,
                    'failed_at' => $failedJob->failed_at ? now()->parse($failedJob->failed_at)->format('Y-m-d H:i:s') : null,
                ];
            }
            table($failedJobsTable);
        }

        if ($action === 'failed-delete') {
            $failedJobs = app(JobRepository::class)->getFailed();
            $failedJobsTable = [];
            foreach ($failedJobs as $failedJob) {
                $failedJobsTable[] = [
                    'id' => $failedJob->id,
                    'name' => $failedJob->name,
                    'failed_at' => $failedJob->failed_at ? now()->parse($failedJob->failed_at)->format('Y-m-d H:i:s') : null,
                ];
            }
            app(MetricsRepository::class)->clear();
            if (count($failedJobsTable) === 0) {
                $this->info('No failed jobs found.');

                return;
            }
            $jobIds = multiselect(
                label: 'Which job to delete?',
                options: collect($failedJobsTable)->mapWithKeys(fn ($job) => [$job['id'] => $job['id'].' - '.$job['name']])->toArray(),
            );
            foreach ($jobIds as $jobId) {
                Artisan::queue('horizon:forget', ['id' => $jobId]);
            }
        }

        if ($action === 'running') {
            $redisJobRepository = app(CustomJobRepository::class);
            $runningJobs = $redisJobRepository->getReservedJobs();
            $runningJobsTable = [];
            if (count($runningJobs) === 0) {
                $this->info('No running jobs found.');

                return;
            }
            foreach ($runningJobs as $runningJob) {
                $runningJobsTable[] = [
                    'id' => $runningJob->id,
                    'name' => $runningJob->name,
                    'reserved_at' => $runningJob->reserved_at ? now()->parse($runningJob->reserved_at)->format('Y-m-d H:i:s') : null,
                ];
            }
            table($runningJobsTable);
        }

        if ($action === 'workers') {
            $redisJobRepository = app(CustomJobRepository::class);
            $workers = $redisJobRepository->getHorizonWorkers();
            $workersTable = [];
            foreach ($workers as $worker) {
                $workersTable[] = [
                    'name' => $worker->name,
                ];
            }
            table($workersTable);
        }

        if ($action === 'purge-queues') {
            $getQueues = app(CustomJobRepository::class)->getQueues();
            $queueName = select(
                label: 'Which queue to purge?',
                options: $getQueues,
            );
            $redisJobRepository = app(RedisJobRepository::class);
            $redisJobRepository->purge($queueName);
        }
    }
}
