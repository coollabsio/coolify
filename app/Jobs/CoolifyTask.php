<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Models\Activity;

class CoolifyTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Activity $activity,
        public bool $ignore_errors = false,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $remote_process = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'ignore_errors' => $this->ignore_errors,
        ]);

        $remote_process();
    }
}
