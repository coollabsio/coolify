<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The configuration snapshot/diff now store an encrypted blob (not valid
     * JSON), so the columns must hold arbitrary text instead of json.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE text USING configuration_snapshot::text');
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE text USING configuration_diff::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE json USING configuration_snapshot::json');
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE json USING configuration_diff::json');
    }
};
