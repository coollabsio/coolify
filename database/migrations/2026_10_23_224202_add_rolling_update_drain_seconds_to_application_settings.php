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
            $table->integer('rolling_update_drain_seconds')
                ->default(5)
                ->after('stop_grace_period')
                ->comment('Seconds to wait after the new container is healthy before old containers stop during a rolling update (0-300). 0 turns the wait off.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('rolling_update_drain_seconds');
        });
    }
};
