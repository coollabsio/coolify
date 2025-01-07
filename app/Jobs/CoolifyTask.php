<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Models\Activity;

class CoolifyTask implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Activity $activity,
        public bool $ignore_errors,
        public $call_event_on_finish,
        public $call_event_data,
    ) {

        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $remote_process = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'ignore_errors' => $this->ignore_errors,
            'call_event_on_finish' => $this->call_event_on_finish,
            'call_event_data' => $this->call_event_data,
        ]);

        $remote_process();
    }
}
