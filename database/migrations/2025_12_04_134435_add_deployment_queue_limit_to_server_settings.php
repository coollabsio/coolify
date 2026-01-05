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
        if (! Schema::hasColumn('server_settings', 'deployment_queue_limit')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->integer('deployment_queue_limit')->default(25)->after('concurrent_builds');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('server_settings', 'deployment_queue_limit')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropColumn('deployment_queue_limit');
            });
        }
    }
};
