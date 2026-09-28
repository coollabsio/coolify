<?php

namespace App\Jobs;

use App\Actions\Node\RecoverStaleOperations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverStaleNodeOperationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        RecoverStaleOperations::run();
    }
}
