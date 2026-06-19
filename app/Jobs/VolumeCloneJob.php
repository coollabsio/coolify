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
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);

        instant_remote_process([
            "docker volume create {$tgtVol}",
            "docker run --rm -v {$srcVol}:/source -v {$tgtVol}:/target alpine sh -c 'cp -a /source/. /target/ && chown -R 1000:1000 /target'",
        ], $this->sourceServer);
    }

    protected function cloneRemoteVolume()
    {
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);
        $sourceCloneDir = "{$this->cloneDir}/{$this->sourceVolume}";
        $targetCloneDir = "{$this->cloneDir}/{$this->targetVolume}";
        $srcDir = escapeshellarg($sourceCloneDir);
        $tgtDir = escapeshellarg($targetCloneDir);

        try {
            instant_remote_process([
                "mkdir -p {$srcDir}",
                "chmod 777 {$srcDir}",
                "docker run --rm -v {$srcVol}:/source -v {$srcDir}:/clone alpine sh -c 'cd /source && tar czf /clone/volume-data.tar.gz .'",
            ], $this->sourceServer);

            instant_remote_process([
                "mkdir -p {$tgtDir}",
                "chmod 777 {$tgtDir}",
            ], $this->targetServer);

            instant_scp(
                "$sourceCloneDir/volume-data.tar.gz",
                "$targetCloneDir/volume-data.tar.gz",
                $this->sourceServer,
                $this->targetServer
            );

            instant_remote_process([
                "docker volume create {$tgtVol}",
                "docker run --rm -v {$tgtVol}:/target -v {$tgtDir}:/clone alpine sh -c 'cd /target && tar xzf /clone/volume-data.tar.gz && chown -R 1000:1000 /target'",
            ], $this->targetServer);

        } catch (\Exception $e) {
            \Log::error("Failed to clone volume {$this->sourceVolume} to {$this->targetVolume}: ".$e->getMessage());
            throw $e;
        } finally {
            try {
                instant_remote_process([
                    "rm -rf {$srcDir}",
                ], $this->sourceServer, false);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up source server clone directory: '.$e->getMessage());
            }

            try {
                if ($this->targetServer) {
                    instant_remote_process([
                        "rm -rf {$tgtDir}",
                    ], $this->targetServer, false);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up target server clone directory: '.$e->getMessage());
            }
        }
    }
}
