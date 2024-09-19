<?php

namespace App\Jobs;

use App\Models\PrivateKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class CleanupSshKeysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $oneWeekAgo = Carbon::now()->subWeek();

        PrivateKey::where('created_at', '<', $oneWeekAgo)
            ->get()
            ->each(function ($privateKey) {
                $privateKey->safeDelete();
            });
    }
}
