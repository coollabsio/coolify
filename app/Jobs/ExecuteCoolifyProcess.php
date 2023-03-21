<?php

namespace App\Jobs;

use App\Actions\RemoteProcess\RemoteProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Contracts\Activity;

class ExecuteCoolifyProcess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Activity $activity,
    ){}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $remoteProcess = resolve(RemoteProcess::class, [
            'activity' => $this->activity,
        ]);

        $remoteProcess();
    }
}
