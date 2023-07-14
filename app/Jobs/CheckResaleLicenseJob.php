<?php

namespace App\Jobs;

use App\Actions\License\CheckResaleLicense;
use App\Models\InstanceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Visus\Cuid2\Cuid2;

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
