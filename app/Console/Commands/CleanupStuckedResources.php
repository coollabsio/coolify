<?php

namespace App\Console\Commands;

use App\Jobs\CleanupHelperContainersJob;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SslCertificate;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Console\Command;

class CleanupStuckedResources extends Command
{
    protected $signature = 'cleanup:stucked-resources';

    protected $description = 'Cleanup Stucked Resources';

    public function handle()
    {
        $this->cleanup_stucked_resources();
    }

    private function cleanup_stucked_resources()
    {
        try {
            $teams = Team::all()->filter(function ($team) {
                return $team->members()->count() === 0 && $team->servers()->count() === 0;
            });
            foreach ($teams as $team) {
                $team->delete();
            }
            $servers = Server::all()->filter(function ($server) {
                return $server->isFunctional();
            });
            if (isCloud()) {
                $servers = $servers->filter(function ($server) {
                    return data_get($server->team->subscription, 'stripe_invoice_paid', false) === true;
                });
            }
            foreach ($servers as $server) {
                CleanupHelperContainersJob::dispatch($server);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stucked resources: {$e->getMessage()}\n";
        }
        try {
            $servers = Server::onlyTrashed()->get();
            foreach ($servers as $server) {
                echo "Force deleting stuck server: {$server->name}\n";
                $server->forceDelete();
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck servers: {$e->getMessage()}\n";
        }
        try {
            $applicationsDeploymentQueue = ApplicationDeploymentQueue::get();
            foreach ($applicationsDeploymentQueue as $applicationDeploymentQueue) {
                if (is_null($applicationDeploymentQueue->application)) {
                    echo "Deleting stuck application deployment queue: {$applicationDeploymentQueue->id}\n";
                    $applicationDeploymentQueue->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck application deployment queue: {$e->getMessage()}\n";
        }
        try {
            $applications = Application::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($applications as $application) {
                echo "Deleting stuck application: {$application->name}\n";
                DeleteResourceJob::dispatch($application);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        try {
            $applicationsPreviews = ApplicationPreview::get();
            foreach ($applicationsPreviews as $applicationPreview) {
                if (! data_get($applicationPreview, 'application')) {
                    echo "Deleting stuck application preview: {$applicationPreview->uuid}\n";
                    DeleteResourceJob::dispatch($applicationPreview);
                }
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        try {
            $applicationsPreviews = ApplicationPreview::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($applicationsPreviews as $applicationPreview) {
                echo "Deleting stuck application preview: {$applicationPreview->fqdn}\n";
                DeleteResourceJob::dispatch($applicationPreview);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        try {
            $postgresqls = StandalonePostgresql::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($postgresqls as $postgresql) {
                echo "Deleting stuck postgresql: {$postgresql->name}\n";
                DeleteResourceJob::dispatch($postgresql);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck postgresql: {$e->getMessage()}\n";
        }
        try {
            $rediss = StandaloneRedis::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($rediss as $redis) {
                echo "Deleting stuck redis: {$redis->name}\n";
                DeleteResourceJob::dispatch($redis);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck redis: {$e->getMessage()}\n";
        }
        try {
            $keydbs = StandaloneKeydb::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($keydbs as $keydb) {
                echo "Deleting stuck keydb: {$keydb->name}\n";
                DeleteResourceJob::dispatch($keydb);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck keydb: {$e->getMessage()}\n";
        }
        try {
            $dragonflies = StandaloneDragonfly::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($dragonflies as $dragonfly) {
                echo "Deleting stuck dragonfly: {$dragonfly->name}\n";
                DeleteResourceJob::dispatch($dragonfly);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck dragonfly: {$e->getMessage()}\n";
        }
        try {
            $clickhouses = StandaloneClickhouse::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($clickhouses as $clickhouse) {
                echo "Deleting stuck clickhouse: {$clickhouse->name}\n";
                DeleteResourceJob::dispatch($clickhouse);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck clickhouse: {$e->getMessage()}\n";
        }
        try {
            $mongodbs = StandaloneMongodb::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($mongodbs as $mongodb) {
                echo "Deleting stuck mongodb: {$mongodb->name}\n";
                DeleteResourceJob::dispatch($mongodb);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck mongodb: {$e->getMessage()}\n";
        }
        try {
            $mysqls = StandaloneMysql::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($mysqls as $mysql) {
                echo "Deleting stuck mysql: {$mysql->name}\n";
                DeleteResourceJob::dispatch($mysql);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck mysql: {$e->getMessage()}\n";
        }
        try {
            $mariadbs = StandaloneMariadb::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($mariadbs as $mariadb) {
                echo "Deleting stuck mariadb: {$mariadb->name}\n";
                DeleteResourceJob::dispatch($mariadb);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck mariadb: {$e->getMessage()}\n";
        }
        try {
            $services = Service::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($services as $service) {
                echo "Deleting stuck service: {$service->name}\n";
                DeleteResourceJob::dispatch($service);
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck service: {$e->getMessage()}\n";
        }
        try {
            $serviceApps = ServiceApplication::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($serviceApps as $serviceApp) {
                echo "Deleting stuck serviceapp: {$serviceApp->name}\n";
                $serviceApp->forceDelete();
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck serviceapp: {$e->getMessage()}\n";
        }
        try {
            $serviceDbs = ServiceDatabase::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($serviceDbs as $serviceDb) {
                echo "Deleting stuck serviceapp: {$serviceDb->name}\n";
                $serviceDb->forceDelete();
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck serviceapp: {$e->getMessage()}\n";
        }
        try {
            $scheduled_tasks = ScheduledTask::all();
            foreach ($scheduled_tasks as $scheduled_task) {
                if (! $scheduled_task->service && ! $scheduled_task->application) {
                    echo "Deleting stuck scheduledtask: {$scheduled_task->name}\n";
                    $scheduled_task->delete();
                }
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck scheduledtasks: {$e->getMessage()}\n";
        }

        try {
            $scheduled_backups = ScheduledDatabaseBackup::all();
            foreach ($scheduled_backups as $scheduled_backup) {
                try {
                    $server = $scheduled_backup->server();
                    if (! $server) {
                        echo "Deleting stuck scheduledbackup: {$scheduled_backup->name}\n";
                        $scheduled_backup->delete();
                    }
                } catch (\Throwable $e) {
                    echo "Error checking server for scheduledbackup {$scheduled_backup->id}: {$e->getMessage()}\n";
                }
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning stuck scheduledbackups: {$e->getMessage()}\n";
        }

        // Cleanup any resources that are not attached to any environment or destination or server
        try {
            $applications = Application::all();
            foreach ($applications as $application) {
                if (! data_get($application, 'environment')) {
                    echo 'Application without environment: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
                if (! $application->destination()) {
                    echo 'Application without destination: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
                if (! data_get($application, 'destination.server')) {
                    echo 'Application without server: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in application: {$e->getMessage()}\n";
        }
        try {
            $postgresqls = StandalonePostgresql::all()->where('id', '!=', 0);
            foreach ($postgresqls as $postgresql) {
                if (! data_get($postgresql, 'environment')) {
                    echo 'Postgresql without environment: '.$postgresql->name.'\n';
                    DeleteResourceJob::dispatch($postgresql);

                    continue;
                }
                if (! $postgresql->destination()) {
                    echo 'Postgresql without destination: '.$postgresql->name.'\n';
                    DeleteResourceJob::dispatch($postgresql);

                    continue;
                }
                if (! data_get($postgresql, 'destination.server')) {
                    echo 'Postgresql without server: '.$postgresql->name.'\n';
                    DeleteResourceJob::dispatch($postgresql);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in postgresql: {$e->getMessage()}\n";
        }
        try {
            $redis = StandaloneRedis::all();
            foreach ($redis as $redis) {
                if (! data_get($redis, 'environment')) {
                    echo 'Redis without environment: '.$redis->name.'\n';
                    DeleteResourceJob::dispatch($redis);

                    continue;
                }
                if (! $redis->destination()) {
                    echo 'Redis without destination: '.$redis->name.'\n';
                    DeleteResourceJob::dispatch($redis);

                    continue;
                }
                if (! data_get($redis, 'destination.server')) {
                    echo 'Redis without server: '.$redis->name.'\n';
                    DeleteResourceJob::dispatch($redis);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in redis: {$e->getMessage()}\n";
        }

        try {
            $mongodbs = StandaloneMongodb::all();
            foreach ($mongodbs as $mongodb) {
                if (! data_get($mongodb, 'environment')) {
                    echo 'Mongodb without environment: '.$mongodb->name.'\n';
                    DeleteResourceJob::dispatch($mongodb);

                    continue;
                }
                if (! $mongodb->destination()) {
                    echo 'Mongodb without destination: '.$mongodb->name.'\n';
                    DeleteResourceJob::dispatch($mongodb);

                    continue;
                }
                if (! data_get($mongodb, 'destination.server')) {
                    echo 'Mongodb without server:  '.$mongodb->name.'\n';
                    DeleteResourceJob::dispatch($mongodb);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mongodb: {$e->getMessage()}\n";
        }

        try {
            $mysqls = StandaloneMysql::all();
            foreach ($mysqls as $mysql) {
                if (! data_get($mysql, 'environment')) {
                    echo 'Mysql without environment: '.$mysql->name.'\n';
                    DeleteResourceJob::dispatch($mysql);

                    continue;
                }
                if (! $mysql->destination()) {
                    echo 'Mysql without destination: '.$mysql->name.'\n';
                    DeleteResourceJob::dispatch($mysql);

                    continue;
                }
                if (! data_get($mysql, 'destination.server')) {
                    echo 'Mysql without server: '.$mysql->name.'\n';
                    DeleteResourceJob::dispatch($mysql);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mysql: {$e->getMessage()}\n";
        }

        try {
            $mariadbs = StandaloneMariadb::all();
            foreach ($mariadbs as $mariadb) {
                if (! data_get($mariadb, 'environment')) {
                    echo 'Mariadb without environment: '.$mariadb->name.'\n';
                    DeleteResourceJob::dispatch($mariadb);

                    continue;
                }
                if (! $mariadb->destination()) {
                    echo 'Mariadb without destination: '.$mariadb->name.'\n';
                    DeleteResourceJob::dispatch($mariadb);

                    continue;
                }
                if (! data_get($mariadb, 'destination.server')) {
                    echo 'Mariadb without server: '.$mariadb->name.'\n';
                    DeleteResourceJob::dispatch($mariadb);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in mariadb: {$e->getMessage()}\n";
        }

        try {
            $services = Service::all();
            foreach ($services as $service) {
                if (! data_get($service, 'environment')) {
                    echo 'Service without environment: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
                if (! $service->destination()) {
                    echo 'Service without destination: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
                if (! data_get($service, 'server')) {
                    echo 'Service without server: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in service: {$e->getMessage()}\n";
        }
        try {
            $serviceApplications = ServiceApplication::all();
            foreach ($serviceApplications as $service) {
                if (! data_get($service, 'service')) {
                    echo 'ServiceApplication without service: '.$service->name.'\n';
                    $service->forceDelete();

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in serviceApplications: {$e->getMessage()}\n";
        }
        try {
            $serviceDatabases = ServiceDatabase::all();
            foreach ($serviceDatabases as $service) {
                if (! data_get($service, 'service')) {
                    echo 'ServiceDatabase without service: '.$service->name.'\n';
                    $service->forceDelete();

                    continue;
                }
            }
        } catch (\Throwable $e) {
            echo "Error in ServiceDatabases: {$e->getMessage()}\n";
        }

        try {
            $orphanedCerts = SslCertificate::whereNotIn('server_id', function ($query) {
                $query->select('id')->from('servers');
            })->get();

            foreach ($orphanedCerts as $cert) {
                echo "Deleting orphaned SSL certificate: {$cert->id} (server_id: {$cert->server_id})\n";
                $cert->delete();
            }
        } catch (\Throwable $e) {
            echo "Error in cleaning orphaned SSL certificates: {$e->getMessage()}\n";
        }
    }
}
