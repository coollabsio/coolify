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

class VolumeRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $archivePath,
        public string $targetVolume,
        public Server $server,
        public ?LocalPersistentVolume $persistentVolume = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $tgtVol = escapeshellarg($this->targetVolume);
        $image = escapeshellarg($this->helperImage());
        $archive = escapeshellarg($this->archivePath);

        instant_remote_process([
            "docker volume create {$tgtVol}",
            "docker run --rm -i -v {$tgtVol}:/target {$image} sh -c 'tar -xzf - -C /target && chown -R 1000:1000 /target' < {$archive}",
        ], $this->server);
    }

    protected function helperImage(): string
    {
        return coolifyHelperImage().':'.getHelperVersion();
    }
}
