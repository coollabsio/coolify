<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Server\CleanupDocker;
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

    public function __construct(
        public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        public bool $deleteConfigurations = true,
        public bool $deleteVolumes = true,
        public bool $dockerCleanup = true,
        public bool $deleteConnectedNetworks = true
    ) {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            switch ($this->resource->type()) {
                case 'application':
                    StopApplication::run($this->resource, previewDeployments: true);
                    break;
                case 'standalone-postgresql':
                case 'standalone-redis':
                case 'standalone-mongodb':
                case 'standalone-mysql':
                case 'standalone-mariadb':
                case 'standalone-keydb':
                case 'standalone-dragonfly':
                case 'standalone-clickhouse':
                    StopDatabase::run($this->resource, true);
                    break;
                case 'service':
                    StopService::run($this->resource, true);
                    DeleteService::run($this->resource, $this->deleteConfigurations, $this->deleteVolumes, $this->dockerCleanup, $this->deleteConnectedNetworks);

                    return;
            }

            if ($this->deleteConfigurations) {
                $this->resource->deleteConfigurations();
            }
            if ($this->deleteVolumes) {
                $this->resource->deleteVolumes();
                $this->resource->persistentStorages()->delete();
            }
            $this->resource->fileStorages()->delete();

            $isDatabase = $this->resource instanceof StandalonePostgresql
            || $this->resource instanceof StandaloneRedis
            || $this->resource instanceof StandaloneMongodb
            || $this->resource instanceof StandaloneMysql
            || $this->resource instanceof StandaloneMariadb
            || $this->resource instanceof StandaloneKeydb
            || $this->resource instanceof StandaloneDragonfly
            || $this->resource instanceof StandaloneClickhouse;

            if ($isDatabase) {
                $this->resource->sslCertificates()->delete();
                $this->resource->scheduledBackups()->delete();
                $this->resource->tags()->detach();
            }
            $this->resource->environment_variables()->delete();

            if ($this->deleteConnectedNetworks && $this->resource->type() === 'application') {
                $this->resource->deleteConnectedNetworks();
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->resource->forceDelete();
            if ($this->dockerCleanup) {
                $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
                if ($server) {
                    CleanupDocker::dispatch($server, true);
                }
            }
            Artisan::queue('cleanup:stucked-resources');
        }
    }
}
