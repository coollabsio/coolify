<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class CoolifyTask implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600;

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

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 90, 180]; // 30s, 90s, 180s between retries
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('CoolifyTask permanently failed', [
            'job' => 'CoolifyTask',
            'activity_id' => $this->activity->id,
            'server_uuid' => $this->activity->getExtraProperty('server_uuid'),
            'command_preview' => substr($this->activity->getExtraProperty('command') ?? '', 0, 200),
            'error' => $exception?->getMessage(),
            'total_attempts' => $this->attempts(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // Update activity status to reflect permanent failure
        $this->activity->properties = $this->activity->properties->merge([
            'status' => ProcessStatus::ERROR->value,
            'error' => $exception?->getMessage() ?? 'Job permanently failed',
            'failed_at' => now()->toIso8601String(),
        ]);
        $this->activity->save();

        // Dispatch cleanup event on failure (same as on success)
        if ($this->call_event_on_finish) {
            try {
                $eventClass = "App\\Events\\$this->call_event_on_finish";
                if (! is_null($this->call_event_data)) {
                    event(new $eventClass($this->call_event_data));
                } else {
                    event(new $eventClass($this->activity->causer_id));
                }
                Log::info('Cleanup event dispatched after job failure', [
                    'event' => $this->call_event_on_finish,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error dispatching cleanup event on failure: '.$e->getMessage());
            }
        }
    }
}
