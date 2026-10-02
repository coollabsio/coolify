<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Support\DatabaseImport\DatabaseImportCleanup;
use App\Support\RemoteProcessCommand;
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
     *
     * @param  int|null  $timeout  Job timeout in seconds; null keeps the default above.
     */
    public function __construct(
        public Activity $activity,
        public bool $ignore_errors,
        public $call_event_on_finish,
        public $call_event_data,
        ?int $timeout = null,
    ) {
        if ($timeout !== null) {
            $this->timeout = $timeout;
        }

        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // A database import that Coolify stopped after a restart must not run again when
        // the queue retries this job. Its cleanup is already queued.
        if (DatabaseImportCleanup::stopRequested($this->activity)) {
            RemoteProcessCommand::forget($this->activity);

            return;
        }

        $remote_process = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'ignore_errors' => $this->ignore_errors,
            'call_event_on_finish' => $this->call_event_on_finish,
            'call_event_data' => $this->call_event_data,
        ]);

        $remote_process();

        // The task is finished. A failed run throws before this line and is removed in failed(),
        // because a retry of the job must still be able to read the command.
        RemoteProcessCommand::forget($this->activity);
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
            'error' => $exception?->getMessage(),
            'total_attempts' => $this->attempts(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // A database import whose restore can still run in the database container stays in progress
        // until its stop has run, so it keeps blocking other operations on the database.
        if (DatabaseImportCleanup::stopAfterTaskFailure($this->activity, $exception?->getMessage() ?: 'Job permanently failed')) {
            RemoteProcessCommand::forget($this->activity);

            return;
        }

        // Update activity status to reflect permanent failure
        // A stopped database import already has the message that explains the stop; the process
        // error ("Terminated") would hide it.
        $stopRequested = DatabaseImportCleanup::stopRequested($this->activity);
        $this->activity->properties = $this->activity->properties->merge(array_filter([
            'status' => ProcessStatus::ERROR->value,
            'error' => $stopRequested ? null : ($exception?->getMessage() ?? 'Job permanently failed'),
            'failed_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null));
        $this->activity->save();

        // No attempt is left, so the command (which can contain secrets) is no longer needed.
        RemoteProcessCommand::forget($this->activity);

        // Dispatch cleanup event on failure (same as on success)
        if ($this->call_event_on_finish) {
            try {
                RunRemoteProcess::dispatchFinishEvent($this->activity, $this->call_event_on_finish, $this->call_event_data);
                Log::info('Cleanup event dispatched after job failure', [
                    'event' => $this->call_event_on_finish,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error dispatching cleanup event on failure: '.$e->getMessage());
            }
        }
    }
}
