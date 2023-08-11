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
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('public_ipv4')->nullable();
            $table->string('public_ipv6')->nullable();
            $table->string('fqdn')->nullable();
            $table->string('wildcard_domain')->nullable();
            $table->string('default_redirect_404')->nullable();
            $table->integer('public_port_min')->default(9000);
            $table->integer('public_port_max')->default(9100);
            $table->boolean('do_not_track')->default(false);
            $table->boolean('is_auto_update_enabled')->default(true);
            $table->boolean('is_registration_enabled')->default(true);
            $table->schemalessAttributes('smtp');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instance_settings');
    }
};
