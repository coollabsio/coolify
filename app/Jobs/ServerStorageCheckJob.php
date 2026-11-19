<?php

namespace App\Jobs;

use App\Models\NotificationThrottle;
use App\Models\Server;
use App\Notifications\Server\HighDiskUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\Silenced;

class ServerStorageCheckJob implements ShouldBeEncrypted, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public int|string|null $percentage = null) {}

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            Log::warning('ServerStorageCheckJob timed out', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
            ]);

            // Delete the queue job so it doesn't appear in Horizon's failed list.
            $this->job?->delete();
        }
    }

    public function handle()
    {
        try {
            if ($this->server->isFunctional() === false) {
                return 'Server is not functional.';
            }
            $team = data_get($this->server, 'team');
            $serverDiskUsageNotificationThreshold = data_get($this->server, 'settings.server_disk_usage_notification_threshold');

            if (is_null($this->percentage)) {
                $this->percentage = $this->server->storageCheck();
            }
            if (! $this->percentage) {
                return 'No percentage could be retrieved.';
            }
            if ($this->percentage > $serverDiskUsageNotificationThreshold) {
                $team->notify(new HighDiskUsage($this->server, $this->percentage, $serverDiskUsageNotificationThreshold));
            } elseif (HighDiskUsage::hasRecovered($this->percentage, $serverDiskUsageNotificationThreshold)) {
                // Usage recovered: the next spike should alert again instead of waiting for the interval.
                NotificationThrottle::release($this->server, HighDiskUsage::class);
            }
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
