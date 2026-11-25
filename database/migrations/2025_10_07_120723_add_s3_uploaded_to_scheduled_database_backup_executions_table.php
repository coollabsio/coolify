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
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->boolean('s3_uploaded')->nullable()->after('filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn('s3_uploaded');
        });
    }
};
