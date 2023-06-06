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
            $table->string('fqdn')->nullable();
            $table->string('wildcard_domain')->nullable();
            $table->string('default_redirect_404')->nullable();
            $table->integer('public_port_min')->default(9000);
            $table->integer('public_port_max')->default(9100);
            $table->boolean('do_not_track')->default(false);
            $table->boolean('is_auto_update_enabled')->default(true);
            $table->boolean('is_registration_enabled')->default(true);

            // SMTP for transactional emails
            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->string('smtp_username')->nullable();
            $table->string('smtp_password')->nullable();
            $table->integer('smtp_timeout')->nullable();
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->string('smtp_test_recipients')->nullable();
            $table->string('smtp_recipients')->nullable();

            // $table->string('custom_dns_servers')->default('1.1.1.1,8.8.8.8');
            // $table->boolean('is_dns_check_enabled')->default(true);
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
