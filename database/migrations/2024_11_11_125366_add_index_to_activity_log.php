<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddIndexToActivityLog extends Migration
{
    public function up()
    {
        try {
            DB::statement('ALTER TABLE activity_log ALTER COLUMN properties TYPE jsonb USING properties::jsonb');
            DB::statement('CREATE INDEX idx_activity_type_uuid ON activity_log USING GIN (properties jsonb_path_ops)');
        } catch (\Exception $e) {
            Log::error('Error adding index to activity_log: '.$e->getMessage());
        }
    }

    public function down()
    {
        try {
            DB::statement('DROP INDEX IF EXISTS idx_activity_type_uuid');
            DB::statement('ALTER TABLE activity_log ALTER COLUMN properties TYPE json USING properties::json');
        } catch (\Exception $e) {
            Log::error('Error dropping index from activity_log: '.$e->getMessage());
        }
    }
}
