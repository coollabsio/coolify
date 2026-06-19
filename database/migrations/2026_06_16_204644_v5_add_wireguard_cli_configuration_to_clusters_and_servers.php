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
        Schema::table('v5_clusters', function (Blueprint $table) {
            $table->string('wireguard_interface')->default('wg0')->after('description');
            $table->string('wireguard_management_pool')->default('100.64.0.0/16')->after('wireguard_interface');
            $table->unsignedInteger('wireguard_listen_port')->default(51820)->after('wireguard_management_pool');
            $table->string('container_network_pool')->default('10.210.0.0/16')->after('wireguard_listen_port');
            $table->unsignedTinyInteger('container_network_prefix')->default(24)->after('container_network_pool');
            $table->json('namespaces')->nullable()->after('container_network_prefix');
            $table->boolean('default_deny_containers')->default(true)->after('namespaces');
            $table->string('coold_version')->default('nightly')->after('default_deny_containers');
            $table->string('corrosion_version')->default('v1.0.0')->after('coold_version');
            $table->unsignedInteger('corrosion_gossip_port')->default(8787)->after('corrosion_version');
            $table->unsignedInteger('corrosion_api_port')->default(8080)->after('corrosion_gossip_port');
            $table->boolean('builder_enabled')->default(true)->after('corrosion_api_port');
            $table->unsignedInteger('builder_capacity')->default(2)->after('builder_enabled');
            $table->string('builder_cpu_quota')->default('200%')->after('builder_capacity');
            $table->string('builder_memory_max')->default('2G')->after('builder_cpu_quota');
            $table->unsignedInteger('builder_timeout_secs')->default(1800)->after('builder_memory_max');
            $table->string('last_cli_action')->nullable()->after('builder_timeout_secs');
            $table->string('last_cli_status')->nullable()->after('last_cli_action');
            $table->text('last_cli_summary')->nullable()->after('last_cli_status');
            $table->timestamp('last_cli_ran_at')->nullable()->after('last_cli_summary');
        });

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('builder_cpu_quota')->default('200%')->after('builder_capacity');
            $table->string('node_address')->nullable()->after('builder_cpu_quota');
            $table->unsignedInteger('wireguard_listen_port_override')->nullable()->after('node_address');
            $table->string('wireguard_endpoint_override')->nullable()->after('wireguard_listen_port_override');
            $table->string('wireguard_management_ip')->nullable()->after('wireguard_endpoint_override');
            $table->string('wireguard_public_key')->nullable()->after('wireguard_management_ip');
            $table->json('container_subnets')->nullable()->after('wireguard_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn([
                'builder_cpu_quota',
                'node_address',
                'wireguard_listen_port_override',
                'wireguard_endpoint_override',
                'wireguard_management_ip',
                'wireguard_public_key',
                'container_subnets',
            ]);
        });

        Schema::table('v5_clusters', function (Blueprint $table) {
            $table->dropColumn([
                'wireguard_interface',
                'wireguard_management_pool',
                'wireguard_listen_port',
                'container_network_pool',
                'container_network_prefix',
                'namespaces',
                'default_deny_containers',
                'coold_version',
                'corrosion_version',
                'corrosion_gossip_port',
                'corrosion_api_port',
                'builder_enabled',
                'builder_capacity',
                'builder_cpu_quota',
                'builder_memory_max',
                'builder_timeout_secs',
                'last_cli_action',
                'last_cli_status',
                'last_cli_summary',
                'last_cli_ran_at',
            ]);
        });
    }
};
