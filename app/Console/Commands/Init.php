<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;

class Init extends Command
{
    protected $signature = 'app:init';
    protected $description = 'Cleanup instance related stuffs';

    public function handle()
    {
        $this->cleanup_in_progress_application_deployments();
    }

    private function cleanup_in_progress_application_deployments()
    {
        // Cleanup any failed deployments

        try {
            $halted_deployments = ApplicationDeploymentQueue::where('status', '==', 'in_progress')->get();
            foreach ($halted_deployments as $deployment) {
                $deployment->status = ApplicationDeploymentStatus::FAILED->value;
                $deployment->save();
            }
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
