<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->unsignedInteger('image_scan_syft_timeout')->default(600);
            $table->unsignedInteger('image_scan_grype_timeout')->default(600);
            $table->decimal('image_scan_cpus', 5, 2)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['image_scan_syft_timeout', 'image_scan_grype_timeout', 'image_scan_cpus']);
        });
    }
};
