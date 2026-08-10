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
        Schema::create('v5_servers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->nullable()->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained('v5_clusters')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('private_key_id')->nullable()->constrained('private_keys')->nullOnDelete();
            $table->string('name');
            $table->string('host');
            $table->string('ssh_user');
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('status')->default('installed');
            $table->string('ingress_type')->nullable();
            $table->string('ingress_status')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('builder_enabled')->default(false);
            $table->unsignedInteger('builder_capacity')->default(0);
            $table->string('builder_cpu_quota')->default('200%');
            $table->string('node_address')->nullable();
            $table->unsignedInteger('wireguard_listen_port_override')->nullable();
            $table->string('wireguard_endpoint_override')->nullable();
            $table->string('wireguard_management_ip')->nullable();
            $table->string('wireguard_public_key')->nullable();
            $table->json('container_subnets')->nullable();
            $table->integer('canvas_x')->nullable();
            $table->integer('canvas_y')->nullable();
            $table->timestamp('last_bootstrapped_at')->nullable();
            $table->string('last_bootstrap_action')->nullable();
            $table->string('last_bootstrap_status')->nullable();
            $table->text('last_bootstrap_output')->nullable();
            $table->timestamp('last_bootstrap_ran_at')->nullable();
            $table->string('last_status_check')->nullable();
            $table->text('last_status_output')->nullable();
            $table->timestamp('last_status_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'host', 'ssh_port']);
            $table->index(['team_id', 'cluster_id']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_servers');
    }
};
