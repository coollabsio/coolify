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
        Schema::create('v5_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('wireguard_interface')->default('wg0');
            $table->string('wireguard_management_pool')->default('100.64.0.0/16');
            $table->unsignedInteger('wireguard_listen_port')->default(51820);
            $table->string('container_network_pool')->default('10.210.0.0/16');
            $table->unsignedTinyInteger('container_network_prefix')->default(24);
            $table->json('namespaces')->nullable();
            $table->boolean('default_deny_containers')->default(true);
            $table->string('coold_version')->default('nightly');
            $table->string('corrosion_version')->default('v1.0.0');
            $table->unsignedInteger('corrosion_gossip_port')->default(8787);
            $table->unsignedInteger('corrosion_api_port')->default(8080);
            $table->boolean('builder_enabled')->default(true);
            $table->unsignedInteger('builder_capacity')->default(2);
            $table->string('builder_cpu_quota')->default('200%');
            $table->string('builder_memory_max')->default('2G');
            $table->unsignedInteger('builder_timeout_secs')->default(1800);
            $table->string('last_cli_action')->nullable();
            $table->string('last_cli_status')->nullable();
            $table->text('last_cli_summary')->nullable();
            $table->timestamp('last_cli_ran_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_clusters');
    }
};
