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
            $table->index('wireguard_management_ip');
            $table->index('node_address');
            $table->index('host');
        });

        Schema::table('v5_applications', function (Blueprint $table) {
            $table->index('runtime_container_id');
        });

        Schema::table('v5_container_statuses', function (Blueprint $table) {
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropIndex(['wireguard_management_ip']);
            $table->dropIndex(['node_address']);
            $table->dropIndex(['host']);
        });

        Schema::table('v5_applications', function (Blueprint $table) {
            $table->dropIndex(['runtime_container_id']);
        });

        Schema::table('v5_container_statuses', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
        });
    }
};
