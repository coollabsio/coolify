<?php

namespace App\Jobs;

use App\Models\LocalFileVolume;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerStorageSaveJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public LocalFileVolume $localFileVolume)
    {
        $this->onQueue('high');
    }

    public function handle()
    {
        $this->localFileVolume->saveStorageOnServer();
    }
}
