<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StopService;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StopResourceJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Application|Service|StandalonePostgresql|StandaloneRedis $resource)
    {
    }

    public function handle()
    {
        try {
            $server = $this->resource->destination->server;
            if (!$server->isFunctional()) {
                return 'Server is not functional';
            }
            switch ($this->resource->type()) {
                case 'application':
                    StopApplication::run($this->resource);
                    break;
                case 'standalone-postgresql':
                    StopDatabase::run($this->resource);
                    break;
                case 'standalone-redis':
                    StopDatabase::run($this->resource);
                    break;
                case 'service':
                    StopService::run($this->resource);
                    break;
            }
        } catch (\Throwable $e) {
            send_internal_notification('ContainerStoppingJob failed with: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->resource->delete();
        }
    }
}
