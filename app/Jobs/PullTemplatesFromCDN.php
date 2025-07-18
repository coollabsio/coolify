<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
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
                $services = $response->json();
                File::put(base_path('templates/service-templates.json'), json_encode($services));
            } else {
                send_internal_notification('PullTemplatesAndVersions failed with: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            send_internal_notification('PullTemplatesAndVersions failed with: '.$e->getMessage());
        }
    }
}
