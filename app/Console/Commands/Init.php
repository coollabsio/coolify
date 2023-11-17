<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class Init extends Command
{
    protected $signature = 'app:init {--cleanup}';
    protected $description = 'Cleanup instance related stuffs';

    public function handle()
    {
        $this->alive();
        $cleanup = $this->option('cleanup');
        if ($cleanup) {
            $this->cleanup_stucked_resources();
            $this->cleanup_ssh();
        }
        $this->cleanup_in_progress_application_deployments();
        $this->cleanup_stucked_helper_containers();
    }
    private function cleanup_stucked_helper_containers() {
        $servers = Server::all();
        foreach ($servers as $server) {
            if ($server->isFunctional()) {
                CleanupHelperContainersJob::dispatch($server);
            }
        }

    }
    private function alive()
    {
        $id = config('app.id');
        $version = config('app.version');
        $settings = InstanceSettings::get();
        $do_not_track = data_get($settings, 'do_not_track');
        if ($do_not_track == true) {
            echo "Skipping alive as do_not_track is enabled\n";
            return;
        }
        try {
            Http::get("https://get.coollabs.io/coolify/v4/alive?appId=$id&version=$version");
            echo "I am alive!\n";
        } catch (\Throwable $e) {
            echo "Error in alive: {$e->getMessage()}\n";
        }
    }
    private function cleanup_ssh()
    {
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
            echo "Error in cleaning ssh: {$e->getMessage()}\n";
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
    private function cleanup_stucked_resources()
    {
        // Cleanup any resources that are not attached to any environment or destination or server
        try {
            $applications = Application::all();
            foreach ($applications as $application) {
                if (!data_get($application, 'environment')) {
                    ray('Application without environment', $application->name);
                    $application->delete();
                }
                if (!data_get($application, 'destination.server')) {
                    ray('Application without server', $application->name);
                    $application->delete();
                }
                if (!$application->destination()) {
                    ray('Application without destination', $application->name);
                    $application->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in application: {$e->getMessage()}\n";
        }
        try {
            $postgresqls = StandalonePostgresql::all();
            foreach ($postgresqls as $postgresql) {
                if (!data_get($postgresql, 'environment')) {
                    ray('Postgresql without environment', $postgresql->name);
                    $postgresql->delete();
                }
                if (!data_get($postgresql, 'destination.server')) {
                    ray('Postgresql without server', $postgresql->name);
                    $postgresql->delete();
                }
                if (!$postgresql->destination()) {
                    ray('Postgresql without destination', $postgresql->name);
                    $postgresql->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in postgresql: {$e->getMessage()}\n";
        }
        try {
            $redis = StandaloneRedis::all();
            foreach ($redis as $redis) {
                if (!data_get($redis, 'environment')) {
                    ray('Redis without environment', $redis->name);
                    $redis->delete();
                }
                if (!data_get($redis, 'destination.server')) {
                    ray('Redis without server', $redis->name);
                    $redis->delete();
                }
                if (!$redis->destination()) {
                    ray('Redis without destination', $redis->name);
                    $redis->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in redis: {$e->getMessage()}\n";
        }

        try {
            $mongodbs = StandaloneMongodb::all();
            foreach ($mongodbs as $mongodb) {
                if (!data_get($mongodb, 'environment')) {
                    ray('Mongodb without environment', $mongodb->name);
                    $mongodb->delete();
                }
                if (!data_get($mongodb, 'destination.server')) {
                    ray('Mongodb without server', $mongodb->name);
                    $mongodb->delete();
                }
                if (!$mongodb->destination()) {
                    ray('Mongodb without destination', $mongodb->name);
                    $mongodb->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mongodb: {$e->getMessage()}\n";
        }

        try {
            $mysqls = StandaloneMysql::all();
            foreach ($mysqls as $mysql) {
                if (!data_get($mysql, 'environment')) {
                    ray('Mysql without environment', $mysql->name);
                    $mysql->delete();
                }
                if (!data_get($mysql, 'destination.server')) {
                    ray('Mysql without server', $mysql->name);
                    $mysql->delete();
                }
                if (!$mysql->destination()) {
                    ray('Mysql without destination', $mysql->name);
                    $mysql->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mysql: {$e->getMessage()}\n";
        }

        try {
            $mariadbs = StandaloneMariadb::all();
            foreach ($mariadbs as $mariadb) {
                if (!data_get($mariadb, 'environment')) {
                    ray('Mariadb without environment', $mariadb->name);
                    $mariadb->delete();
                }
                if (!data_get($mariadb, 'destination.server')) {
                    ray('Mariadb without server', $mariadb->name);
                    $mariadb->delete();
                }
                if (!$mariadb->destination()) {
                    ray('Mariadb without destination', $mariadb->name);
                    $mariadb->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mariadb: {$e->getMessage()}\n";
        }

        try {
            $services = Service::all();
            foreach ($services as $service) {
                if (!data_get($service, 'environment')) {
                    ray('Service without environment', $service->name);
                    $service->delete();
                }
                if (!data_get($service, 'server')) {
                    ray('Service without server', $service->name);
                    $service->delete();
                }
                if (!$service->destination()) {
                    ray('Service without destination', $service->name);
                    $service->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in service: {$e->getMessage()}\n";
        }
        try {
            $serviceApplications = ServiceApplication::all();
            foreach ($serviceApplications as $service) {
                if (!data_get($service, 'service')) {
                    ray('ServiceApplication without service', $service->name);
                    $service->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in serviceApplications: {$e->getMessage()}\n";
        }
        try {
            $serviceDatabases = ServiceDatabase::all();
            foreach ($serviceDatabases as $service) {
                if (!data_get($service, 'service')) {
                    ray('ServiceDatabase without service', $service->name);
                    $service->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in ServiceDatabases: {$e->getMessage()}\n";
        }
    }
}
