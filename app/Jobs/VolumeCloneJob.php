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

    public function __construct(
        protected string $sourceVolume,
        protected string $targetVolume,
        protected Server $server,
        protected LocalPersistentVolume $persistentVolume
    ) {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            instant_remote_process([
                "docker volume create $this->targetVolume",
                "docker run --rm -v $this->sourceVolume:/source -v $this->targetVolume:/target alpine sh -c 'cp -a /source/. /target/ && chown -R 1000:1000 /target'",
            ], $this->server);
        } catch (\Exception $e) {
            logger()->error("Failed to copy volume data for {$this->sourceVolume}: ".$e->getMessage());
            throw $e;
        }
    }
}
