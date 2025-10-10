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
            // CREATE INDEX CONCURRENTLY cannot run inside a transaction block
            // We need to commit any open transaction first
            DB::commit();

            // Add specific index for type_uuid queries with ordering
            DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_activity_type_uuid_created_at ON activity_log ((properties->>\'type_uuid\'), created_at DESC)');

            // Add specific index for status queries on properties
            DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_activity_properties_status ON activity_log ((properties->>\'status\'))');

            // Begin a new transaction for subsequent migrations
            DB::beginTransaction();
        } catch (\Exception $e) {
            Log::error('Error adding optimized indexes to activity_log: '.$e->getMessage());
            // Ensure we have a transaction for subsequent migrations
            DB::beginTransaction();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            // DROP INDEX CONCURRENTLY cannot run inside a transaction block
            DB::commit();

            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS idx_activity_type_uuid_created_at');
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS idx_activity_properties_status');

            DB::beginTransaction();
        } catch (\Exception $e) {
            Log::error('Error dropping optimized indexes from activity_log: '.$e->getMessage());
            DB::beginTransaction();
        }
    }
};
