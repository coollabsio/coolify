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
            $persistentStorages = collect();
            switch ($this->resource->type()) {
                case 'application':
                    $persistentStorages = $this->resource?->persistentStorages()?->get();
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
                    $persistentStorages = $this->resource?->persistentStorages()?->get();
                    StopDatabase::run($this->resource, true);
                    break;
                case 'service':
                    StopService::run($this->resource, true);
                    DeleteService::run($this->resource, $this->deleteConfigurations, $this->deleteVolumes, $this->dockerCleanup, $this->deleteConnectedNetworks);
                    break;
            }

            if ($this->deleteVolumes && $this->resource->type() !== 'service') {
                $this->resource?->delete_volumes($persistentStorages);
            }
            if ($this->deleteConfigurations) {
                $this->resource?->delete_configurations();
            }

            $isDatabase = $this->resource instanceof StandalonePostgresql
                || $this->resource instanceof StandaloneRedis
                || $this->resource instanceof StandaloneMongodb
                || $this->resource instanceof StandaloneMysql
                || $this->resource instanceof StandaloneMariadb
                || $this->resource instanceof StandaloneKeydb
                || $this->resource instanceof StandaloneDragonfly
                || $this->resource instanceof StandaloneClickhouse;
            $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
            if (($this->dockerCleanup || $isDatabase) && $server) {
                CleanupDocker::dispatch($server, true);
            }

            if ($this->deleteConnectedNetworks && ! $isDatabase) {
                $this->resource?->delete_connected_networks($this->resource->uuid);
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->resource->forceDelete();
            if ($this->dockerCleanup) {
                CleanupDocker::dispatch($server, true);
            }
            Artisan::queue('cleanup:stucked-resources');
        }
    }
}
