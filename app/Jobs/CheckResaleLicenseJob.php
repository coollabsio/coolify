<?php

namespace App\Jobs;

use App\Actions\License\CheckResaleLicense;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckResaleLicenseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        try {
            resolve(CheckResaleLicense::class)();
        } catch (\Throwable $th) {
            ray($th);
        }
    }
}
