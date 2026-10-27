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
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->unsignedInteger('server_validation_rate_limit_seconds')->default(600);
            $table->unsignedInteger('server_validation_max_failures_per_ip')->default(10);
            $table->unsignedInteger('server_validation_max_failures_per_team')->default(30);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'server_validation_rate_limit_seconds',
                'server_validation_max_failures_per_ip',
                'server_validation_max_failures_per_team',
            ]);
        });
    }
};
