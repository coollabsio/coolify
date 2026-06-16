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
            $table->foreignId('cluster_id')
                ->nullable()
                ->after('team_id')
                ->constrained('v5_clusters')
                ->nullOnDelete();

            $table->index(['team_id', 'cluster_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'cluster_id']);
            $table->dropConstrainedForeignId('cluster_id');
        });
    }
};
