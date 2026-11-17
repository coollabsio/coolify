<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE shared_environment_variables DROP CONSTRAINT IF EXISTS shared_environment_variables_type_check');
                DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type IN ('team', 'project', 'environment', 'server'))");
            }
            Schema::table('shared_environment_variables', function (Blueprint $table) {
                $table->foreignId('server_id')->nullable()->constrained()->onDelete('cascade');
                // NULL != NULL in PostgreSQL unique indexes, so this only enforces uniqueness
                // for server-scoped rows (where server_id is non-null). Other scopes are covered
                // by existing unique constraints on ['key', 'project_id', 'team_id'] and ['key', 'environment_id', 'team_id'].
                $table->unique(['key', 'server_id', 'team_id']);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function () {
            Schema::table('shared_environment_variables', function (Blueprint $table) {
                $table->dropUnique(['key', 'server_id', 'team_id']);
                $table->dropForeign(['server_id']);
                $table->dropColumn('server_id');
            });
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE shared_environment_variables DROP CONSTRAINT IF EXISTS shared_environment_variables_type_check');
                DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type IN ('team', 'project', 'environment'))");
            }
        });
    }
};
