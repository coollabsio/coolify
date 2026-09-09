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
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->timestamp('status_observed_at')->nullable()->after('status');
        });

        Schema::table('v5_applications', function (Blueprint $table) {
            $table->timestamp('status_observed_at')->nullable()->after('status_message');
        });

        Schema::table('v5_container_statuses', function (Blueprint $table) {
            $table->timestamp('status_observed_at')->nullable()->after('status_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn('status_observed_at');
        });

        Schema::table('v5_applications', function (Blueprint $table) {
            $table->dropColumn('status_observed_at');
        });

        Schema::table('v5_container_statuses', function (Blueprint $table) {
            $table->dropColumn('status_observed_at');
        });
    }
};
