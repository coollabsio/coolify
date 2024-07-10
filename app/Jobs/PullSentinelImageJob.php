<?php

namespace App\Jobs;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PullSentinelImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))];
    }

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            $version = get_latest_sentinel_version();
            if (! $version) {
                ray('Failed to get latest Sentinel version');

                return;
            }
            $local_version = instant_remote_process(['docker exec coolify-sentinel sh -c "curl http://127.0.0.1:8888/api/version"'], $this->server, false);
            if (empty($local_version)) {
                $local_version = '0.0.0';
            }
            if (version_compare($local_version, $version, '<')) {
                StartSentinel::run($this->server, $version, true);

                return;
            }
            ray('Sentinel image is up to date');
        } catch (\Throwable $e) {
            // send_internal_notification('PullSentinelImageJob failed with: '.$e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
