<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InstanceApplicationsStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $applications;
    public function __construct()
    {
        $this->applications = Application::all();
    }
    public function handle(): void
    {
        try {
            foreach ($this->applications as $application) {
                dispatch(new ApplicationContainerStatusJob(
                    application: $application,
                    container_name: generate_container_name($application->uuid),
                ));
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
        }
    }
}
