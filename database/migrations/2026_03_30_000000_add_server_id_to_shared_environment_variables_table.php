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
            $table->foreignId('server_id')->nullable()->constrained()->onDelete('cascade')->after('environment_id');
            $table->unique(['key', 'server_id', 'team_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shared_environment_variables MODIFY COLUMN type ENUM('team', 'project', 'environment', 'server') NOT NULL DEFAULT 'team'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shared_environment_variables MODIFY COLUMN type ENUM('team', 'project', 'environment') NOT NULL DEFAULT 'team'");
        }

        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->dropUnique(['key', 'server_id', 'team_id']);
            $table->dropConstrainedForeignId('server_id');
        });
    }
};
