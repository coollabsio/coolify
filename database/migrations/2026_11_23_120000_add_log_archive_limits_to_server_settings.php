<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->integer('log_archive_max_file_mb')->default(10);
            $table->integer('log_archive_max_total_mb')->default(1024);
            $table->integer('log_archive_retention_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'log_archive_max_file_mb',
                'log_archive_max_total_mb',
                'log_archive_retention_days',
            ]);
        });
    }
};
