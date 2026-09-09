<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('server_settings')
            ->where('is_sentinel_enabled', false)
            ->where('is_build_server', false)
            ->where('is_swarm_manager', false)
            ->where('is_swarm_worker', false)
            ->where('force_disabled', false)
            ->where('is_reachable', true)
            ->where('is_usable', true)
            ->update(['is_sentinel_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing values cannot be distinguished from values enabled before this migration.
    }
};
