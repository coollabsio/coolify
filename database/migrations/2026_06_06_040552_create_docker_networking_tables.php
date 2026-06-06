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
        Schema::create('docker_networks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('docker_network_name');
            $table->string('driver')->default('unknown');
            $table->string('scope')->default('unknown');
            $table->string('subnet')->nullable();
            $table->string('gateway')->nullable();
            $table->string('ip_range')->nullable();
            $table->json('aux_addresses')->nullable();
            $table->boolean('internal')->default(false);
            $table->boolean('proxy_access')->nullable()->default(null);
            $table->boolean('available_during_creation')->default(false);
            $table->boolean('attachable')->default(true);
            $table->boolean('enable_ipv6')->default(false);
            $table->json('labels')->nullable();
            $table->json('options')->nullable();
            $table->boolean('managed_by_coolify')->default(false);
            $table->boolean('external')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('source_type')->default('unknown');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('network_role')->default('unknown');
            $table->timestamp('last_inspected_at')->nullable();
            $table->json('last_inspect_data')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'docker_network_name']);
            $table->index('server_id');
            $table->index(['server_id', 'is_active']);
            $table->index(['server_id', 'source_type']);
            $table->index(['server_id', 'network_role']);
        });

        Schema::create('network_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('docker_network_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('service_name')->nullable();
            $table->string('container_name')->nullable();
            $table->string('container_id')->nullable();
            $table->json('aliases')->nullable();
            $table->string('ipv4_address')->nullable();
            $table->string('ipv6_address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_managed')->default(false);
            $table->boolean('is_runtime_discovered')->default(false);
            $table->string('status')->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->index('docker_network_id');
            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('status');
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->string('predefined_network')->nullable()->after('connect_to_docker_network');
            $table->boolean('managed_network_mode')->default(false)->after('predefined_network');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('predefined_network')->nullable()->after('connect_to_docker_network');
            $table->boolean('managed_network_mode')->default(false)->after('predefined_network');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['predefined_network', 'managed_network_mode']);
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['predefined_network', 'managed_network_mode']);
        });

        Schema::dropIfExists('network_attachments');
        Schema::dropIfExists('docker_networks');
    }
};
