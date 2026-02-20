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
        Schema::table('application_databases', function (Blueprint $table) {
            $table->string('image')->nullable();
            $table->string('public_port')->nullable();
            $table->string('custom_type')->nullable();
            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_stripprefix_enabled')->default(true);
            $table->boolean('is_gzip_enabled')->default(true);
            $table->timestamp('last_online_at')->nullable();
            $table->integer('restart_count')->default(0);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_databases', function (Blueprint $table) {
            $table->dropColumn([
                'image',
                'public_port',
                'custom_type',
                'is_log_drain_enabled',
                'is_stripprefix_enabled',
                'is_gzip_enabled',
                'last_online_at',
                'restart_count',
                'last_restart_at',
                'last_restart_type',
            ]);
        });
    }
};
