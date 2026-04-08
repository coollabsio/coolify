<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class PullTemplatesFromCDN implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 10;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            if (isDev()) {
                return;
            }
            $response = Http::retry(3, 1000)->get(config('constants.services.official'));
            if ($response->successful()) {
                $services = collect($response->json() ?? []);
                // persist_service_templates_catalog() re-injects any locally
                // protected templates (e.g. laravel-rootkit) before writing
                // so the scheduled CDN refresh never drops them.
                persist_service_templates_catalog($services);
            } else {
                send_internal_notification('PullTemplatesAndVersions failed with: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            send_internal_notification('PullTemplatesAndVersions failed with: '.$e->getMessage());
        }
    }
}
