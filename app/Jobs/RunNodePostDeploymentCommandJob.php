<?php

namespace App\Jobs;

use App\Actions\Node\RunNodeDeploymentCommand;
use App\Actions\Node\UpdateNodeWorkloadRuntimeOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The post-deployment command of a cluster application after every server of the deploy succeeded (decision 0032). */
class RunNodePostDeploymentCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = UpdateNodeWorkloadRuntimeOptions::MAX_COMMAND_TIMEOUT + 120;

    public function __construct(public string $notificationGroup) {}

    public function handle(): void
    {
        RunNodeDeploymentCommand::post($this->notificationGroup);
    }
}
