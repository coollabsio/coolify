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
            $table->bigInteger('hostinger_virtual_machine_id')->nullable()->after('digitalocean_droplet_status');
            $table->string('hostinger_virtual_machine_status')->nullable()->after('hostinger_virtual_machine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'hostinger_virtual_machine_status',
                'hostinger_virtual_machine_id',
            ]);
        });
    }
};
