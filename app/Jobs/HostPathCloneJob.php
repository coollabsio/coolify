<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Copy a bind-mount host path from one Coolify-managed server to another.
 */
class HostPathCloneJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $cloneDir = '/data/coolify/clone';

    public int $timeout = 3600;

    public function __construct(
        protected string $sourcePath,
        protected string $targetPath,
        protected Server $sourceServer,
        protected Server $targetServer
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        if ($this->sourceServer->id === $this->targetServer->id && $this->sourcePath === $this->targetPath) {
            return;
        }

        if ($this->sourceServer->id === $this->targetServer->id) {
            $this->cloneLocalPath();

            return;
        }

        $this->cloneRemotePath();
    }

    protected function cloneLocalPath(): void
    {
        $src = escapeshellarg($this->sourcePath);
        $tgt = escapeshellarg($this->targetPath);
        $tgtParent = escapeshellarg(dirname($this->targetPath));

        instant_remote_process([
            "mkdir -p {$tgtParent}",
            "mkdir -p {$tgt}",
            "docker run --rm -v {$src}:/source:ro -v {$tgt}:/target alpine sh -c 'cp -a /source/. /target/'",
        ], $this->sourceServer);
    }

    protected function cloneRemotePath(): void
    {
        $archiveName = 'hostpath-data.tar.gz';
        $token = Str::uuid()->toString();
        $sourceCloneDir = "{$this->cloneDir}/hostpath-{$token}";
        $targetCloneDir = "{$this->cloneDir}/hostpath-{$token}";
        $srcDir = escapeshellarg($sourceCloneDir);
        $tgtDir = escapeshellarg($targetCloneDir);
        $srcPath = escapeshellarg($this->sourcePath);
        $tgtPath = escapeshellarg($this->targetPath);
        $tgtParent = escapeshellarg(dirname($this->targetPath));
        $localTempDir = storage_path('app/tmp/hostpath-clones/'.$token);
        $localArchive = $localTempDir.'/'.$archiveName;

        try {
            File::ensureDirectoryExists($localTempDir, 0755);

            instant_remote_process([
                "mkdir -p {$srcDir}",
                "chmod 777 {$srcDir}",
                "test -e {$srcPath}",
                "docker run --rm -v {$srcPath}:/source:ro -v {$srcDir}:/clone alpine sh -c 'cd /source && tar czf /clone/{$archiveName} .'",
            ], $this->sourceServer);

            instant_remote_process([
                "mkdir -p {$tgtDir}",
                "chmod 777 {$tgtDir}",
            ], $this->targetServer);

            instant_scp_from_server(
                "{$sourceCloneDir}/{$archiveName}",
                $localArchive,
                $this->sourceServer
            );

            instant_scp(
                $localArchive,
                "{$targetCloneDir}/{$archiveName}",
                $this->targetServer
            );

            instant_remote_process([
                "mkdir -p {$tgtParent}",
                "mkdir -p {$tgtPath}",
                "docker run --rm -v {$tgtPath}:/target -v {$tgtDir}:/clone alpine sh -c 'cd /target && tar xzf /clone/{$archiveName}'",
            ], $this->targetServer);
        } catch (\Exception $e) {
            \Log::error("Failed to clone host path {$this->sourcePath} to {$this->targetPath}: ".$e->getMessage());
            throw $e;
        } finally {
            try {
                File::deleteDirectory($localTempDir);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up local host-path clone directory: '.$e->getMessage());
            }

            try {
                instant_remote_process(["rm -rf {$srcDir}"], $this->sourceServer, false);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up source host-path clone directory: '.$e->getMessage());
            }

            try {
                instant_remote_process(["rm -rf {$tgtDir}"], $this->targetServer, false);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up target host-path clone directory: '.$e->getMessage());
            }
        }
    }
}
