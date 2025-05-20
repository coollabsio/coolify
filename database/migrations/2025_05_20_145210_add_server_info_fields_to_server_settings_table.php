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
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('cpu_model')->nullable();
            $table->integer('cpu_cores')->nullable();
            $table->string('cpu_speed')->nullable();
            $table->string('memory_total')->nullable();
            $table->string('memory_speed')->nullable();
            $table->string('swap_total')->nullable();
            $table->string('disk_total')->nullable();
            $table->string('disk_used')->nullable();
            $table->string('disk_free')->nullable();
            $table->string('gpu_model')->nullable();
            $table->string('gpu_memory')->nullable();
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('kernel_version')->nullable();
            $table->string('architecture')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('cpu_model');
            $table->dropColumn('cpu_cores');
            $table->dropColumn('cpu_speed');
            $table->dropColumn('memory_total');
            $table->dropColumn('memory_speed');
            $table->dropColumn('swap_total');
            $table->dropColumn('disk_total');
            $table->dropColumn('disk_used');
            $table->dropColumn('disk_free');
            $table->dropColumn('gpu_model');
            $table->dropColumn('gpu_memory');
            $table->dropColumn('os_name');
            $table->dropColumn('os_version');
            $table->dropColumn('kernel_version');
            $table->dropColumn('architecture');
        });
    }
};
