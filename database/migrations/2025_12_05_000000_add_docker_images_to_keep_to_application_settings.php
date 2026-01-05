<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'docker_images_to_keep')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->integer('docker_images_to_keep')->default(2);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('application_settings', 'docker_images_to_keep')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('docker_images_to_keep');
            });
        }
    }
};
