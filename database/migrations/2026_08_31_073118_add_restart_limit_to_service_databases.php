<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->integer('restart_count')->default(0);
            $table->integer('max_restart_count')->default(10);
            $table->boolean('restart_limit_reached')->default(false);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn([
                'restart_count',
                'max_restart_count',
                'restart_limit_reached',
                'last_restart_at',
                'last_restart_type',
            ]);
        });
    }
};
