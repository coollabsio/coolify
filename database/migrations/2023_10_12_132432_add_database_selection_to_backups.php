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
            $table->text('databases_to_backup')->nullable();
        });
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->string('database_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('databases_to_backup');
        });
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn('database_name');
        });
    }
};
