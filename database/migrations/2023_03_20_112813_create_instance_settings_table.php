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
            $table->id()->primary();
            $table->string('fqdn')->nullable();
            $table->string('wildcard_domain')->nullable();
            $table->string('redirect_url')->nullable();
            // $table->string('preview_domain_separator')->default('.');
            $table->integer('public_port_min')->default(9000);
            $table->integer('public_port_max')->default(9100);
            // $table->string('custom_dns_servers')->default('1.1.1.1,8.8.8.8');

            $table->boolean('do_not_track')->default(false);

            $table->boolean('is_auto_update_enabled')->default(true);
            // $table->boolean('is_dns_check_enabled')->default(true);
            $table->boolean('is_registration_enabled')->default(true);
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
