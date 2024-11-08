<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CheckHelperImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;

    public function __construct() {}

    public function handle(): void
    {
        try {
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();
                $settings = instanceSettings();
                $latest_version = data_get($versions, 'coolify.helper.version');
                $current_version = $settings->helper_version;
                if (version_compare($latest_version, $current_version, '>')) {
                    $settings->update(['helper_version' => $latest_version]);
                }
            }
        } catch (\Throwable $e) {
            send_internal_notification('CheckHelperImageJob failed with: '.$e->getMessage());
            throw $e;
        }
    }
}
