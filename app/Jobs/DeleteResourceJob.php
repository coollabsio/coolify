<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource, public bool $deleteConfigurations = false) {}

    public function handle()
    {
        try {
            $this->resource->forceDelete();
            switch ($this->resource->type()) {
                case 'application':
                    StopApplication::run($this->resource);
                    break;
                case 'standalone-postgresql':
                case 'standalone-redis':
                case 'standalone-mongodb':
                case 'standalone-mysql':
                case 'standalone-mariadb':
                case 'standalone-keydb':
                case 'standalone-dragonfly':
                case 'standalone-clickhouse':
                    StopDatabase::run($this->resource);
                    break;
                case 'service':
                    StopService::run($this->resource);
                    DeleteService::run($this->resource);
                    break;
            }
            if ($this->deleteConfigurations) {
                $this->resource?->delete_configurations();
            }
        } catch (\Throwable $e) {
            ray($e->getMessage());
            send_internal_notification('ContainerStoppingJob failed with: '.$e->getMessage());
            throw $e;
        } finally {
            Artisan::queue('cleanup:stucked-resources');
        }
    }
}
