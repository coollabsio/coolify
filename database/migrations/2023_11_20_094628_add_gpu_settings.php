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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_gpu_enabled')->default(false);
            $table->string('gpu_driver')->default('nvidia');
            $table->string('gpu_count')->nullable();
            $table->string('gpu_device_ids')->nullable();
            $table->longText('gpu_options')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_gpu_enabled');
            $table->dropColumn('gpu_driver');
            $table->dropColumn('gpu_count');
            $table->dropColumn('gpu_device_ids');
            $table->dropColumn('gpu_options');
        });
    }
};
