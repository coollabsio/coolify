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
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('auto_image_pull_enabled')->default(false)->after('service_type');
            $table->string('auto_image_pull_schedule')->nullable()->after('auto_image_pull_enabled');
            $table->timestamp('last_image_pull_check')->nullable()->after('auto_image_pull_schedule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['auto_image_pull_enabled', 'auto_image_pull_schedule', 'last_image_pull_check']);
        });
    }
};
