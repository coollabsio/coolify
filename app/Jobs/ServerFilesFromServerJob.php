<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerFilesFromServerJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Only one sync per resource can wait in the queue. The compose parser dispatches
     * this job once per volume on every parse, so without this the queue fills with copies.
     */
    public int $uniqueFor = 600;

    public function __construct(public ServiceApplication|ServiceDatabase|Application $resource)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return $this->resource::class.':'.$this->resource->id;
    }

    public function handle()
    {
        $this->resource->getFilesFromServer(isInit: true);
    }
}
