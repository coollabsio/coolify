<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;

class CheckApplicationDeploymentQueue extends Command
{
    protected $signature = 'check:deployment-queue {--force} {--seconds=3600}';

    protected $description = 'Check application deployment queue.';

    public function handle()
    {
        $seconds = $this->option('seconds');
        $deployments = ApplicationDeploymentQueue::whereIn('status', [
            ApplicationDeploymentStatus::IN_PROGRESS,
            ApplicationDeploymentStatus::QUEUED,
        ])->where('created_at', '<=', now()->subSeconds($seconds))->get();
        if ($deployments->isEmpty()) {
            $this->info('No deployments found in the last '.$seconds.' seconds.');

            return;
        }

        $this->info('Found '.$deployments->count().' deployments created in the last '.$seconds.' seconds.');

        foreach ($deployments as $deployment) {
            if ($this->option('force')) {
                $this->info('Deployment '.$deployment->id.' created at '.$deployment->created_at.' is older than '.$seconds.' seconds. Setting status to failed.');
                $this->cancelDeployment($deployment);
            } else {
                $this->info('Deployment '.$deployment->id.' created at '.$deployment->created_at.' is older than '.$seconds.' seconds. Setting status to failed.');
                if ($this->confirm('Do you want to cancel this deployment?', true)) {
                    $this->cancelDeployment($deployment);
                }
            }
        }
    }

    private function cancelDeployment(ApplicationDeploymentQueue $deployment)
    {
        $deployment->update(['status' => ApplicationDeploymentStatus::FAILED]);
        if ($deployment->server?->isFunctional()) {
            remote_process(['docker rm -f '.$deployment->deployment_uuid], $deployment->server, false);
        }
    }
}
