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
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'digitalocean_droplet_id')) {
                $table->bigInteger('digitalocean_droplet_id')->nullable()->after('hetzner_server_status');
            }

            if (! Schema::hasColumn('servers', 'digitalocean_droplet_status')) {
                $table->string('digitalocean_droplet_status')->nullable()->after('digitalocean_droplet_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'digitalocean_droplet_status')) {
                $table->dropColumn('digitalocean_droplet_status');
            }

            if (Schema::hasColumn('servers', 'digitalocean_droplet_id')) {
                $table->dropColumn('digitalocean_droplet_id');
            }
        });
    }
};
