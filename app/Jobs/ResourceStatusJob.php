<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\StandalonePostgresql;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResourceStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $applications;
    public $postgresqls;

    public function __construct()
    {
        $this->applications = Application::all();
        $this->postgresqls = StandalonePostgresql::all();
    }

    public function handle(): void
    {
        try {
            foreach ($this->applications as $application) {
                dispatch(new ApplicationContainerStatusJob(
                    application: $application,
                ));
            }
            foreach ($this->postgresqls as $postgresql) {
                dispatch(new DatabaseContainerStatusJob(
                    database: $postgresql,
                ));
            }
        } catch (\Exception $th) {
            send_internal_notification('ResourceStatusJob failed with: ' . $th->getMessage());
            ray($th);
            throw $th;
        }
    }
}
