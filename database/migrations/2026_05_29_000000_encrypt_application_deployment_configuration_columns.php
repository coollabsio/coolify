<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The configuration snapshot/diff now store an encrypted blob (not valid
     * JSON), so the columns must hold arbitrary text instead of json.
     *
     * Coolify's own backend runs exclusively on PostgreSQL in production and
     * SQLite in testing (see config/database.php — the only configured
     * connections are `pgsql` and `testing`). MySQL/MariaDB are user-managed
     * resources, never Coolify's application database, so no driver path is
     * needed for them here.
     */
    public function up(): void
    {
        // SQLite (testing) uses type affinity, so json columns already accept text.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE text USING configuration_snapshot::text');
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE text USING configuration_diff::text');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE json USING configuration_snapshot::json');
        DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE json USING configuration_diff::json');
    }
};
