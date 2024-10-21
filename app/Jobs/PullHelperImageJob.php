<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullHelperImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            $helperImage = config('coolify.helper_image');
            $latest_version = instanceSettings()->helper_version;
            instant_remote_process(["docker pull -q {$helperImage}:{$latest_version}"], $this->server, false);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
