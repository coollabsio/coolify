<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS swarm_dockers_server_id_index ON swarm_dockers (server_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS services_server_id_index ON services (server_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS application_previews_application_id_index ON application_previews (application_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS service_applications_service_id_index ON service_applications (service_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS service_databases_service_id_index ON service_databases (service_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS servers_sentinel_updated_at_index ON servers (sentinel_updated_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS swarm_dockers_server_id_index');
        DB::statement('DROP INDEX IF EXISTS services_server_id_index');
        DB::statement('DROP INDEX IF EXISTS application_previews_application_id_index');
        DB::statement('DROP INDEX IF EXISTS service_applications_service_id_index');
        DB::statement('DROP INDEX IF EXISTS service_databases_service_id_index');
        DB::statement('DROP INDEX IF EXISTS servers_sentinel_updated_at_index');
    }
};
