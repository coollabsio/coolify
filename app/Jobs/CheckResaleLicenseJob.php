<?php

namespace App\Jobs;

use App\Actions\License\CheckResaleLicense;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckResaleLicenseJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        try {
            CheckResaleLicense::run();
        } catch (\Throwable $e) {
            send_internal_notification('CheckResaleLicenseJob failed with: '.$e->getMessage());
            ray($e);
            throw $e;
        }
    }
}
