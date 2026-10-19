<?php

namespace App\Jobs;

use App\Actions\Server\CheckUpdates;
use App\Events\ServerPatchCheckFinished;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServerPatchCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 1;

    /**
     * @param  bool  $manual  A check that a user started from the patches page. It saves the result also for a
     *                        server that is not functional, and it sends no "updates available" notification.
     */
    public function __construct(public Server $server, public bool $manual = false)
    {
        $this->onQueue(maintenance_queue());
    }

    public static function runningCacheKey(Server $server): string
    {
        return "server-patch-check-running:{$server->uuid}";
    }

    /**
     * Queues a manual check and marks it as running, so the patches page shows the progress after a reload.
     */
    public static function dispatchManual(Server $server): void
    {
        Cache::put(self::runningCacheKey($server), true, now()->addMinutes(15));
        self::dispatch($server, true);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-patch-check-'.$this->server->uuid))->expireAfter(600)->dontRelease()];
    }

    public function handle(): void
    {
        try {
            $team = data_get($this->server, 'team');
            if (! $team || $this->server->isTransferredAway()) {
                return;
            }

            if ($this->server->isFunctional() === false) {
                if ($this->manual) {
                    $this->saveResult(['error' => 'Server is not reachable or not ready.']);
                }

                return;
            }

            $previousError = $this->server->settings->patch_check_error;
            $patchData = CheckUpdates::run($this->server);
            $this->saveResult($patchData);

            if (isset($patchData['error'])) {
                // Notify only when the state changes from ok to error, not on every check of a broken server.
                if (blank($previousError)) {
                    $team->notify(new ServerPatchCheck($this->server, $patchData));
                }

                return;
            }

            if (! $this->manual && ($patchData['total_updates'] ?? 0) > 0) {
                $team->notify(new ServerPatchCheck($this->server, $patchData));
            }
        } catch (\Throwable $e) {
            Log::error('ServerPatchCheckJob failed: '.$e->getMessage(), [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->saveResult(['error' => $e->getMessage()]);
        } finally {
            Cache::forget(self::runningCacheKey($this->server));
            ServerPatchCheckFinished::dispatch($this->server->team_id, $this->server->uuid);
        }
    }

    /**
     * @param  array<string, mixed>  $patchData
     */
    private function saveResult(array $patchData): void
    {
        $error = $patchData['error'] ?? null;

        $this->server->settings->forceFill([
            'patch_check_result' => $error === null ? $patchData : Arr::only($patchData, ['osId', 'package_manager']),
            'patch_check_error' => $error === null ? null : (string) $error,
            'patch_checked_at' => now(),
        ])->saveQuietly();
    }
}
