<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Service;
use App\Models\StandaloneMongodb;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Init extends Command
{
    protected $signature = 'app:init';
    protected $description = 'Cleanup instance related stuffs';

    public function handle()
    {
        ray()->clearAll();
        $this->cleanup_in_progress_application_deployments();
        $this->cleanup_stucked_resources();
        $this->cleanup_ssh();
    }

    private function cleanup_ssh() {
        try {
            $files = Storage::allFiles('ssh/keys');
            foreach ($files as $file) {
                Storage::delete($file);
            }
            $files = Storage::allFiles('ssh/mux');
            foreach ($files as $file) {
                Storage::delete($file);
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
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
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
    private function cleanup_stucked_resources() {
        // Cleanup any resources that are not attached to any environment or destination or server
        try {
            $applications = Application::all();
            foreach($applications as $application) {
                if (!$application->environment) {
                    ray('Application without environment', $application->name);
                    $application->delete();
                }
                if (!$application->destination()) {
                    ray('Application without destination', $application->name);
                    $application->delete();
                }
            }
            $postgresqls = StandalonePostgresql::all();
            foreach($postgresqls as $postgresql) {
                if (!$postgresql->environment) {
                    ray('Postgresql without environment', $postgresql->name);
                    $postgresql->delete();
                }
                if (!$postgresql->destination()) {
                    ray('Postgresql without destination', $postgresql->name);
                    $postgresql->delete();
                }
            }
            $redis = StandaloneRedis::all();
            foreach($redis as $redis) {
                if (!$redis->environment) {
                    ray('Redis without environment', $redis->name);
                    $redis->delete();
                }
                if (!$redis->destination()) {
                    ray('Redis without destination', $redis->name);
                    $redis->delete();
                }
            }
            $mongodbs = StandaloneMongodb::all();
            foreach($mongodbs as $mongodb) {
                if (!$mongodb->environment) {
                    ray('Mongodb without environment', $mongodb->name);
                    $mongodb->delete();
                }
                if (!$mongodb->destination()) {
                    ray('Mongodb without destination', $mongodb->name);
                    $mongodb->delete();
                }
            }
            $services = Service::all();
            foreach($services as $service) {
                if (!$service->environment) {
                    ray('Service without environment', $service->name);
                    $service->delete();
                }
                if (!$service->server) {
                    ray('Service without server', $service->name);
                    $service->delete();
                }
                if (!$service->destination()) {
                    ray('Service without destination', $service->name);
                    $service->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
