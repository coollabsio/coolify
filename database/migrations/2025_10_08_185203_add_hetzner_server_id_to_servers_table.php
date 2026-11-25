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
        if (! Schema::hasColumn('servers', 'hetzner_server_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->bigInteger('hetzner_server_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'hetzner_server_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('hetzner_server_id');
            });
        }
    }
};
