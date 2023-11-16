<?php

namespace App\Jobs;

use App\Models\Server;
use App\Notifications\Server\HighDiskUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ServerStatusJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server)
    {
    }
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->id))->dontRelease()];
    }

    public function uniqueId(): int
    {
        return $this->server->id;
    }

    public function handle(): void
    {
        ray("checking server status for {$this->server->id}");
        try {

            $this->server->checkServerRediness();
            $disk_usage = $this->server->getDiskUsage();
            ray($this->server->settings->cleanup_after_percentage);
            if ($disk_usage >= $this->server->settings->cleanup_after_percentage) {
                $this->server->high_disk_usage_notification_sent = true;
                $this->server->save();
                $this->server->team->notify(new HighDiskUsage($this->server, $disk_usage, $this->server->settings->cleanup_after_percentage));
            } else {
                $this->server->high_disk_usage_notification_sent = false;
                $this->server->save();
            }
        } catch (\Throwable $e) {
            send_internal_notification('ServerStatusJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            handleError($e);
        }
    }
}
