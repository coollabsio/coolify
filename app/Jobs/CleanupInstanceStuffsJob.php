<?php

namespace App\Jobs;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupInstanceStuffsJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('cleanup-instance-stuffs'))->dontRelease()];
    }

    public function handle(): void
    {
        try {
            $this->cleanupInvitationLink();
        } catch (\Throwable $e) {
            Log::error('CleanupInstanceStuffsJob failed with error: '.$e->getMessage());
        }
    }

    private function cleanupInvitationLink()
    {
        $invitation = TeamInvitation::all();
        foreach ($invitation as $item) {
            $item->isValid();
        }
    }
}
