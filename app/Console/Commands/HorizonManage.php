<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Repositories\CustomJobRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Repositories\RedisJobRepository;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

class HorizonManage extends Command
{
    protected $signature = 'horizon:manage {--can-i-restart-this-worker} {--job-status=}';

    protected $description = 'Manage horizon';

    public function handle()
    {
        if ($this->option('can-i-restart-this-worker')) {
            return $this->isThereAJobInProgress();
        }

        if ($this->option('job-status')) {
            return $this->getJobStatus($this->option('job-status'));
        }

        $action = select(
            label: 'What to do?',
            options: [
                'pending' => 'Pending Jobs',
                'running' => 'Running Jobs',
                'can-i-restart-this-worker' => 'Can I restart this worker?',
                'job-status' => 'Job Status',
                'workers' => 'Workers',
                'failed' => 'Failed Jobs',
                'failed-delete' => 'Failed Jobs - Delete',
                'purge-queues' => 'Purge Queues',
            ]
        );

        if ($action === 'can-i-restart-this-worker') {
            $this->isThereAJobInProgress();
        }

        if ($action === 'job-status') {
            $jobId = text('Which job to check?');
            $jobStatus = $this->getJobStatus($jobId);
            $this->info('Job Status: '.$jobStatus);
        }

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

    public function isThereAJobInProgress()
    {
        $runningJobs = ApplicationDeploymentQueue::where('horizon_job_worker', gethostname())->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)->get();
        $count = $runningJobs->count();
        if ($count === 0) {
            return false;
        }

        return true;
    }

    public function getJobStatus(string $jobId)
    {
        return getJobStatus($jobId);
    }
}
