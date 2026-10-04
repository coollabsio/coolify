<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('unreachable_since')->nullable();
            $table->timestamp('unreachable_notified_at')->nullable();
        });

        Schema::table('node_clusters', function (Blueprint $table) {
            $table->timestamp('network_unhealthy_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['unreachable_since', 'unreachable_notified_at']);
        });

        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropColumn('network_unhealthy_notified_at');
        });
    }
};
