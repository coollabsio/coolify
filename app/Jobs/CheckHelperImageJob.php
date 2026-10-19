<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckHelperImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;

    public function __construct() {}

    public function handle(): void
    {
        $response = Http::retry(3, 1000, throw: false)->get(config('constants.coolify.versions_url'));
        if ($response->failed()) {
            // The CDN can be briefly unavailable. The next scheduled run tries again, so do not notify.
            Log::warning('CheckHelperImageJob could not fetch versions', ['status' => $response->status()]);

            return;
        }

        $latest_version = data_get($response->json(), 'coolify.helper.version');
        $settings = instanceSettings();
        if (version_compare($latest_version, $settings->helper_version, '>')) {
            $settings->update(['helper_version' => $latest_version]);
        }
    }
}
