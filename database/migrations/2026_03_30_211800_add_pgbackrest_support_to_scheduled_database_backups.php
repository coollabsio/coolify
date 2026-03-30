<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->boolean('use_pgbackrest')->default(false)->comment('Use pgBackRest for PostgreSQL incremental backups');
            $table->text('pgbackrest_config')->nullable()->comment('Custom pgBackRest configuration options');
            $table->string('backup_type')->default('full')->comment('pgBackRest backup type: full, incremental, differential');
            $table->integer('full_backup_frequency')->default(7)->comment('Days between full backups when using incremental');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn(['use_pgbackrest', 'pgbackrest_config', 'backup_type', 'full_backup_frequency']);
        });
    }
};