<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('environment_id')->constrained()->onDelete('cascade');
        });

        // Update the type CHECK constraint to include 'server'
        DB::statement('ALTER TABLE shared_environment_variables DROP CONSTRAINT shared_environment_variables_type_check');
        DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type::text = ANY (ARRAY['team'::text, 'project'::text, 'environment'::text, 'server'::text]))");

        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->unique(['key', 'server_id', 'team_id']);
        });
    }

    public function down(): void
    {
        // Delete all server-type variables first
        DB::table('shared_environment_variables')->where('type', 'server')->delete();

        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->dropUnique(['key', 'server_id', 'team_id']);
            $table->dropForeign(['server_id']);
            $table->dropColumn('server_id');
        });

        // Restore original type CHECK constraint
        DB::statement('ALTER TABLE shared_environment_variables DROP CONSTRAINT shared_environment_variables_type_check');
        DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type::text = ANY (ARRAY['team'::text, 'project'::text, 'environment'::text]))");
    }
};
