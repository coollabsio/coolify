<?php

namespace App\Jobs;

use App\Models\LocalPersistentVolume;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VolumeCloneJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $cloneDir = '/data/coolify/clone';

    public function __construct(
        protected string $sourceVolume,
        protected string $targetVolume,
        protected Server $sourceServer,
        protected ?Server $targetServer,
        protected LocalPersistentVolume $persistentVolume
    ) {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            if (! $this->targetServer || $this->targetServer->id === $this->sourceServer->id) {
                $this->cloneLocalVolume();
            } else {
                $this->cloneRemoteVolume();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to copy volume data for {$this->sourceVolume}: ".$e->getMessage());
            throw $e;
        }
    }

    protected function cloneLocalVolume()
    {
        instant_remote_process([
            "docker volume create $this->targetVolume",
            "docker run --rm -v $this->sourceVolume:/source -v $this->targetVolume:/target alpine sh -c 'cp -a /source/. /target/ && chown -R 1000:1000 /target'",
        ], $this->sourceServer);
    }

    protected function cloneRemoteVolume()
    {
        $sourceCloneDir = "{$this->cloneDir}/{$this->sourceVolume}";
        $targetCloneDir = "{$this->cloneDir}/{$this->targetVolume}";

        try {
            instant_remote_process([
                "mkdir -p $sourceCloneDir",
                "chmod 777 $sourceCloneDir",
                "docker run --rm -v $this->sourceVolume:/source -v $sourceCloneDir:/clone alpine sh -c 'cd /source && tar czf /clone/volume-data.tar.gz .'",
            ], $this->sourceServer);

            instant_remote_process([
                "mkdir -p $targetCloneDir",
                "chmod 777 $targetCloneDir",
            ], $this->targetServer);

            instant_scp(
                "$sourceCloneDir/volume-data.tar.gz",
                "$targetCloneDir/volume-data.tar.gz",
                $this->sourceServer,
                $this->targetServer
            );

            instant_remote_process([
                "docker volume create $this->targetVolume",
                "docker run --rm -v $this->targetVolume:/target -v $targetCloneDir:/clone alpine sh -c 'cd /target && tar xzf /clone/volume-data.tar.gz && chown -R 1000:1000 /target'",
            ], $this->targetServer);

        } catch (\Exception $e) {
            \Log::error("Failed to clone volume {$this->sourceVolume} to {$this->targetVolume}: ".$e->getMessage());
            throw $e;
        } finally {
            try {
                instant_remote_process([
                    "rm -rf $sourceCloneDir",
                ], $this->sourceServer, false);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up source server clone directory: '.$e->getMessage());
            }

            try {
                if ($this->targetServer) {
                    instant_remote_process([
                        "rm -rf $targetCloneDir",
                    ], $this->targetServer, false);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up target server clone directory: '.$e->getMessage());
            }
        }
    }
}
