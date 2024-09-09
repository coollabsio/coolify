<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

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
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();
                $settings = InstanceSettings::get();
                $latest_version = data_get($versions, 'coolify.helper.version');
                $current_version = $settings->helper_version;
                if (version_compare($latest_version, $current_version, '>')) {
                    // New version available
                    $helperImage = config('coolify.helper_image');
                    instant_remote_process(["docker pull -q {$helperImage}:{$latest_version}"], $this->server);
                    $settings->update(['helper_version' => $latest_version]);
                }
            }

        } catch (\Throwable $e) {
            send_internal_notification('PullHelperImageJob failed with: '.$e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
