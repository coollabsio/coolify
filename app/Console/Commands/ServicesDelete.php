<?php

namespace App\Console\Commands;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ServicesDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a service from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $resource = select(
            'What service do you want to delete?',
            ['Application', 'Database', 'Service', 'Server'],
        );
        if ($resource === 'Application') {
            $this->deleteApplication();
        } elseif ($resource === 'Database') {
            $this->deleteDatabase();
        } elseif ($resource === 'Service') {
            $this->deleteService();
        } elseif ($resource === 'Server') {
            $this->deleteServer();
        }
    }

    private function deleteServer()
    {
        $servers = Server::all();
        if ($servers->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $serversToDelete = multiselect(
            label: 'What server do you want to delete?',
            options: $servers->pluck('name', 'id')->sortKeys(),
        );

        foreach ($serversToDelete as $server) {
            $toDelete = $servers->where('id', $server)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    break;
                }
                $toDelete->delete();
            }
        }
    }

    private function deleteApplication()
    {
        $applications = Application::all();
        if ($applications->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $applicationsToDelete = multiselect(
            'What application do you want to delete?',
            $applications->pluck('name', 'id')->sortKeys(),
        );

        foreach ($applicationsToDelete as $application) {
            $toDelete = $applications->where('id', $application)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources? ');
                if (! $confirmed) {
                    break;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }

    private function deleteDatabase()
    {
        // Collect all databases from all types with unique identifiers
        $allDatabases = collect();
        $databaseOptions = collect();

        // Add PostgreSQL databases
        foreach (StandalonePostgresql::all() as $db) {
            $key = "postgresql_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (PostgreSQL)");
        }

        // Add MySQL databases
        foreach (StandaloneMysql::all() as $db) {
            $key = "mysql_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (MySQL)");
        }

        // Add MariaDB databases
        foreach (StandaloneMariadb::all() as $db) {
            $key = "mariadb_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (MariaDB)");
        }

        // Add MongoDB databases
        foreach (StandaloneMongodb::all() as $db) {
            $key = "mongodb_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (MongoDB)");
        }

        // Add Redis databases
        foreach (StandaloneRedis::all() as $db) {
            $key = "redis_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (Redis)");
        }

        // Add KeyDB databases
        foreach (StandaloneKeydb::all() as $db) {
            $key = "keydb_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (KeyDB)");
        }

        // Add Dragonfly databases
        foreach (StandaloneDragonfly::all() as $db) {
            $key = "dragonfly_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (Dragonfly)");
        }

        // Add ClickHouse databases
        foreach (StandaloneClickhouse::all() as $db) {
            $key = "clickhouse_{$db->id}";
            $allDatabases->put($key, $db);
            $databaseOptions->put($key, "{$db->name} (ClickHouse)");
        }

        if ($allDatabases->count() === 0) {
            $this->error('There are no databases to delete.');

            return;
        }

        $databasesToDelete = multiselect(
            'What database do you want to delete?',
            $databaseOptions->sortKeys(),
        );

        foreach ($databasesToDelete as $databaseKey) {
            $toDelete = $allDatabases->get($databaseKey);
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }

    private function deleteService()
    {
        $services = Service::all();
        if ($services->count() === 0) {
            $this->error('There are no services to delete.');

            return;
        }
        $servicesToDelete = multiselect(
            'What service do you want to delete?',
            $services->pluck('name', 'id')->sortKeys(),
        );

        foreach ($servicesToDelete as $service) {
            $toDelete = $services->where('id', $service)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }
}
