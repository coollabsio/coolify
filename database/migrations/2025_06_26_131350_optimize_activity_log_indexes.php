<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Add specific index for type_uuid queries with ordering
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_activity_type_uuid_created_at ON activity_log ((properties->>\'type_uuid\'), created_at DESC)');

            // Add specific index for status queries on properties
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_activity_properties_status ON activity_log ((properties->>\'status\'))');

        } catch (\Exception $e) {
            Log::error('Error adding optimized indexes to activity_log: '.$e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_activity_type_uuid_created_at');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_activity_properties_status');
        } catch (\Exception $e) {
            Log::error('Error dropping optimized indexes from activity_log: '.$e->getMessage());
        }
    }
};
