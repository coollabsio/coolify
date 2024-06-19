<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PullHelperImageJob implements ShouldBeEncrypted, ShouldQueue
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
            $helperImage = config('coolify.helper_image');
            ray("Pulling {$helperImage}");
            instant_remote_process(["docker pull -q {$helperImage}"], $this->server, false);
            ray('PullHelperImageJob done');
        } catch (\Throwable $e) {
            send_internal_notification('PullHelperImageJob failed with: '.$e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
