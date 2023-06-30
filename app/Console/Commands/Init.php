<?php

namespace App\Console\Commands;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;

class Init extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup instance related stuffs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $halted_deployments = ApplicationDeploymentQueue::where('status', '==', 'in_progress')->get();
            ray($halted_deployments);
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
