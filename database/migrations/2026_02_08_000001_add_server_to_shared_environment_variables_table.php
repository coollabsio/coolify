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
        DB::statement("ALTER TABLE shared_environment_variables DROP CONSTRAINT shared_environment_variables_type_check");
        DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type IN ('team', 'project', 'environment', 'server'))");
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->constrained()->onDelete('cascade');
            $table->unique(['key', 'server_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->dropUnique(['key', 'server_id', 'team_id']);
            $table->dropForeign(['server_id']);
            $table->dropColumn('server_id');
        });
        DB::statement("ALTER TABLE shared_environment_variables DROP CONSTRAINT shared_environment_variables_type_check");
        DB::statement("ALTER TABLE shared_environment_variables ADD CONSTRAINT shared_environment_variables_type_check CHECK (type IN ('team', 'project', 'environment'))");
    }
};
