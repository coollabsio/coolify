<?php

namespace App\Jobs;

use App\Models\Server;
use App\Notifications\Server\HighDiskUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;

class ServerStorageCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public ?int $percentage = null) {}

    public function handle()
    {
        try {
            if (! $this->server->isFunctional()) {
                ray('Server is not ready.');

                return 'Server is not ready.';
            }
            $team = data_get($this->server, 'team');
            $serverDiskUsageNotificationThreshold = data_get($this->server, 'settings.server_disk_usage_notification_threshold');

            if (is_null($this->percentage)) {
                $this->percentage = $this->server->storageCheck();
            }
            if (! $this->percentage) {
                throw new \Exception('No percentage could be retrieved.');
            }
            if ($this->percentage > $serverDiskUsageNotificationThreshold) {
                $executed = RateLimiter::attempt(
                    'high-disk-usage:'.$this->server->id,
                    $maxAttempts = 0,
                    function () use ($team, $serverDiskUsageNotificationThreshold) {
                        $team->notify(new HighDiskUsage($this->server, $this->percentage, $serverDiskUsageNotificationThreshold));
                    },
                    $decaySeconds = 3600,
                );

                if (! $executed) {
                    throw new \Exception('Too many messages sent!');
                }
            } else {
                RateLimiter::hit('high-disk-usage:'.$this->server->id, 600);
            }

        } catch (\Throwable $e) {
            return handleError($e);
        }

    }
}
