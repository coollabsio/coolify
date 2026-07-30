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

class PullTemplatesFromCDN implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;

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
            $response = Http::retry(3, 1000, throw: false)
                ->timeout(60)
                ->connectTimeout(10)
                ->get(config('constants.services.official'));
            if ($response->successful()) {
                // Shared cache so Cloud HTTP nodes see the same bundle Horizon pulled.
                store_service_templates_bundle($response->body());
            } else {
                Log::error('PullTemplatesFromCDN failed', [
                    'status' => $response->status(),
                    'body' => str($response->body())->limit(500)->toString(),
                ]);
                send_internal_notification('PullTemplatesAndVersions failed with: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('PullTemplatesFromCDN exception', [
                'message' => $e->getMessage(),
            ]);
            send_internal_notification('PullTemplatesAndVersions failed with: '.$e->getMessage());
        }
    }
}
