<?php

namespace App\Jobs;

use App\Models\Waitlist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupInstanceStuffsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {

    }

    // public function uniqueId(): string
    // {
    //     return $this->container_name;
    // }

    public function handle(): void
    {
        try {
            // $this->cleanup_waitlist();
        } catch (\Exception $e) {
            send_internal_notification('CleanupInstanceStuffsJob failed with error: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }

    private function cleanup_waitlist()
    {
        $waitlist = Waitlist::whereVerified(false)->where('created_at', '<', now()->subMinutes(config('constants.waitlist.expiration')))->get();
        foreach ($waitlist as $item) {
            $item->delete();
        }
    }
}
