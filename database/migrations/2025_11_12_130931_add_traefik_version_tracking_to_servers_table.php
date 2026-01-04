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
        if (! Schema::hasColumn('servers', 'detected_traefik_version')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('detected_traefik_version')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'detected_traefik_version')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('detected_traefik_version');
            });
        }
    }
};
