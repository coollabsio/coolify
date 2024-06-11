<?php

namespace App\Jobs;

use App\Traits\ExecuteRemoteCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationRestartJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public string $applicationDeploymentQueueId;

    public function __construct(string $applicationDeploymentQueueId)
    {
        $this->applicationDeploymentQueueId = $applicationDeploymentQueueId;
    }

    public function handle()
    {
        ray('Restarting application');
    }
}
