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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VolumeCloneJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $cloneDir = '/data/coolify/clone';

    public int $timeout = 3600;

    public function __construct(
        protected string $sourceVolume,
        protected string $targetVolume,
        protected Server $sourceServer,
        protected ?Server $targetServer,
        protected LocalPersistentVolume $persistentVolume
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
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

    protected function cloneLocalVolume(): void
    {
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);

        // Same-name local copy is a no-op (used when only the network destination changes).
        if ($this->sourceVolume === $this->targetVolume) {
            return;
        }

        instant_remote_process([
            "docker volume create {$tgtVol}",
            "docker run --rm -v {$srcVol}:/source -v {$tgtVol}:/target alpine sh -c 'cp -a /source/. /target/ && chown -R 1000:1000 /target'",
        ], $this->sourceServer);
    }

    protected function cloneRemoteVolume(): void
    {
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);
        $sourceCloneDir = "{$this->cloneDir}/{$this->sourceVolume}";
        $targetCloneDir = "{$this->cloneDir}/{$this->targetVolume}";
        $srcDir = escapeshellarg($sourceCloneDir);
        $tgtDir = escapeshellarg($targetCloneDir);
        $localTempDir = storage_path('app/tmp/volume-clones/'.Str::uuid()->toString());
        $localArchive = $localTempDir.'/volume-data.tar.gz';

        try {
            File::ensureDirectoryExists($localTempDir, 0755);

            instant_remote_process([
                "mkdir -p {$srcDir}",
                "chmod 777 {$srcDir}",
                "docker run --rm -v {$srcVol}:/source -v {$srcDir}:/clone alpine sh -c 'cd /source && tar czf /clone/volume-data.tar.gz .'",
            ], $this->sourceServer);

            instant_remote_process([
                "mkdir -p {$tgtDir}",
                "chmod 777 {$tgtDir}",
            ], $this->targetServer);

            // Coolify host is the intermediary: download from source, upload to target.
            instant_scp_from_server(
                "{$sourceCloneDir}/volume-data.tar.gz",
                $localArchive,
                $this->sourceServer
            );

            instant_scp(
                $localArchive,
                "{$targetCloneDir}/volume-data.tar.gz",
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
                File::deleteDirectory($localTempDir);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up local volume clone directory: '.$e->getMessage());
            }

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
