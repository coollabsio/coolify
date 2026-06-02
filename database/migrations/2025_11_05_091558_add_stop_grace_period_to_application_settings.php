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
            $table->integer('stop_grace_period')
                ->nullable()
                ->after('use_build_secrets')
                ->comment('Seconds to wait for graceful shutdown before forcing container stop (1-3600). Null uses default of 30 seconds.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('stop_grace_period');
        });
    }
};
