<?php

use App\Jobs\RefreshImageScanSummariesJob;
use App\Models\ImageScan;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stores the security status (spec 15.6) on the current scans once: one queued summary refresh per team with scans.
     */
    public function up(): void
    {
        try {
            Server::query()
                ->whereIn('id', ImageScan::query()->select('server_id')->distinct())
                ->distinct()
                ->pluck('team_id')
                ->filter(fn ($teamId): bool => $teamId !== null)
                ->each(fn ($teamId) => RefreshImageScanSummariesJob::dispatch((int) $teamId));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
