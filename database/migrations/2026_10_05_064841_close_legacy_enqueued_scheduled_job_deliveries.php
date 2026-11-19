<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ScheduledJobDeliveryService::ENQUEUED_STALE_AFTER_MINUTES when this migration was written.
     */
    private const STALE_AFTER_MINUTES = 60;

    /**
     * v4.3.23 left dropped overlapping jobs enqueued; stale recovery would report them as missed runs.
     */
    public function up(): void
    {
        DB::table('scheduled_job_deliveries')
            ->where('status', 'enqueued')
            ->where('enqueued_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'status' => 'skipped',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
