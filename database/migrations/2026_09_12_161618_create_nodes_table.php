<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('cidr');
            $table->string('wireguard_interface')->default('coolify0');
            $table->unsignedInteger('wireguard_port')->default(51820);
            $table->unsignedBigInteger('desired_revision')->default(1);
            $table->string('network_status')->default('pending');
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'name']);
            $table->unique('cidr');
        });

        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('private_key_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('role')->default('worker')->index();
            $table->string('ip');
            $table->unsignedInteger('port')->default(22);
            $table->string('user')->default('root');
            $table->string('wireguard_ip')->nullable();
            $table->string('workload_cidr')->nullable()->unique();
            $table->string('wireguard_public_key')->nullable();
            $table->string('wireguard_endpoint')->nullable();
            $table->unsignedBigInteger('network_applied_revision')->nullable();
            $table->json('network_observed_state')->nullable();
            $table->timestamp('wireguard_last_handshake_at')->nullable();
            $table->string('corrosion_status')->nullable();
            $table->string('corrosion_version')->nullable();
            $table->text('sentinel_token')->nullable();
            $table->string('sentinel_url')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_reachable')->default(false);
            $table->boolean('is_usable')->default(false);
            $table->text('validation_logs')->nullable();
            $table->timestamps();
            $table->unique(['node_cluster_id', 'wireguard_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('node_clusters');
    }
};
