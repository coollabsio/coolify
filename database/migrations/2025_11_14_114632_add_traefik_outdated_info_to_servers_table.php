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
        if (! Schema::hasColumn('servers', 'traefik_outdated_info')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->json('traefik_outdated_info')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'traefik_outdated_info')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('traefik_outdated_info');
            });
        }
    }
};
