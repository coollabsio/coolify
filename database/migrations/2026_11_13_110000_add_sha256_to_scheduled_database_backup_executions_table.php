<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dumps of cluster databases (decision 0010): the checksum that a restore checks on the Node. */
    public function up(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
