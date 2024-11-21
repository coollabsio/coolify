<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddIndexToActivityLog extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE activity_log ALTER COLUMN properties TYPE jsonb USING properties::jsonb');
        DB::statement('CREATE INDEX idx_activity_type_uuid ON activity_log USING GIN (properties jsonb_path_ops)');
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS idx_activity_type_uuid');
        DB::statement('ALTER TABLE activity_log ALTER COLUMN properties TYPE json USING properties::json');

    }
}
