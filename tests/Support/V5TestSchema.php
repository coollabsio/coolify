<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for the v5_* table definitions used by tests that
 * build their schema by hand instead of running the full migration set.
 *
 * The column definitions must exactly mirror the final state produced by the
 * database/migrations-v5 files (including the 2026_07_05/2026_07_06
 * additions: status_observed_at, coold_version, has_coold/is_ingress booleans
 * replacing the dropped capabilities json, and NOT NULL server uuids). Update
 * this class in the same change as any v5 migration.
 *
 * Cross-table foreign key constraints and the composite unique indexes on
 * v5_servers (team_id/host/ssh_port) and v5_clusters (team_id/name) are
 * intentionally omitted: tests seed sparse rows (team_id => 1 without a teams
 * row) and rely on application-level validation, matching the previous
 * hand-rolled schemas. Unique indexes that encode behaviour are kept.
 */
class V5TestSchema
{
    public static function createAllTables(): void
    {
        self::createClustersTable();
        self::createServersTable();
        self::createContainerStatusesTable();
        self::createApplicationsTable();
        self::createApplicationDomainsTable();
        self::createResourceConnectionsTable();
        self::createResourceConnectionRulesTable();
        self::createRevokedAgentTokensTable();
    }

    public static function dropAllTables(): void
    {
        Schema::dropIfExists('v5_revoked_agent_tokens');
        Schema::dropIfExists('v5_resource_connection_rules');
        Schema::dropIfExists('v5_resource_connections');
        Schema::dropIfExists('v5_application_domains');
        Schema::dropIfExists('v5_applications');
        Schema::dropIfExists('v5_container_statuses');
        Schema::dropIfExists('v5_servers');
        Schema::dropIfExists('v5_clusters');
    }

    public static function createClustersTable(): void
    {
        Schema::create('v5_clusters', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id');
            $table->foreignId('created_by_user_id');
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
        });
    }

    public static function createServersTable(): void
    {
        Schema::create('v5_servers', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id');
            $table->foreignId('cluster_id')->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('private_key_id')->nullable();
            $table->string('name');
            $table->string('host');
            $table->string('ssh_user');
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('status')->default('installed');
            $table->timestamp('status_observed_at')->nullable();
            $table->string('ingress_type')->nullable();
            $table->string('ingress_status')->nullable();
            $table->boolean('has_coold')->default(false)->index();
            $table->boolean('is_ingress')->default(false)->index();
            $table->boolean('builder_enabled')->default(false);
            $table->unsignedInteger('builder_capacity')->default(0);
            $table->string('builder_cpu_quota')->default('200%');
            $table->string('node_address')->nullable();
            $table->unsignedInteger('wireguard_listen_port_override')->nullable();
            $table->string('wireguard_endpoint_override')->nullable();
            $table->string('wireguard_management_ip')->nullable();
            $table->string('wireguard_public_key')->nullable();
            $table->string('coold_version')->nullable();
            $table->string('agent_token_jti')->nullable();
            $table->timestamp('agent_token_expires_at')->nullable();
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
        });
    }

    public static function createContainerStatusesTable(): void
    {
        Schema::create('v5_container_statuses', function ($table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('server_id');
            $table->string('container_id');
            $table->string('container_name')->nullable();
            $table->string('image')->nullable();
            $table->string('status')->default('unknown');
            $table->text('status_message')->nullable();
            $table->timestamp('status_observed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['server_id', 'container_id']);
        });
    }

    public static function createApplicationsTable(): void
    {
        Schema::create('v5_applications', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id');
            $table->foreignId('project_id');
            $table->foreignId('environment_id');
            $table->foreignId('server_id')->nullable();
            $table->foreignId('created_by_user_id');
            $table->string('name');
            $table->string('image');
            $table->string('container_name')->unique();
            $table->string('status')->default('creating');
            $table->text('status_message')->nullable();
            $table->timestamp('status_observed_at')->nullable();
            $table->string('runtime_container_id')->nullable();
            $table->string('mesh_namespace')->default('default');
            $table->boolean('ingress_enabled')->default(false);
            $table->unsignedSmallInteger('internal_port')->nullable();
            $table->integer('canvas_x')->default(0);
            $table->integer('canvas_y')->default(0);
            $table->timestamps();
        });
    }

    public static function createApplicationDomainsTable(): void
    {
        Schema::create('v5_application_domains', function ($table) {
            $table->id();
            $table->foreignId('application_id');
            $table->string('domain');
            $table->timestamps();

            $table->unique(['application_id', 'domain']);
        });
    }

    public static function createResourceConnectionsTable(): void
    {
        Schema::create('v5_resource_connections', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id');
            $table->foreignId('project_id');
            $table->foreignId('environment_id');
            $table->string('resource_one_type');
            $table->unsignedBigInteger('resource_one_id');
            $table->string('resource_two_type');
            $table->unsignedBigInteger('resource_two_id');
            $table->string('resource_pair_key');
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->unique(['team_id', 'resource_pair_key']);
        });
    }

    public static function createRevokedAgentTokensTable(): void
    {
        Schema::create('v5_revoked_agent_tokens', function ($table) {
            $table->id();
            $table->string('jti')->unique();
            $table->foreignId('server_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public static function createResourceConnectionRulesTable(): void
    {
        Schema::create('v5_resource_connection_rules', function ($table) {
            $table->id();
            $table->foreignId('connection_id');
            $table->string('source_resource_type');
            $table->unsignedBigInteger('source_resource_id');
            $table->string('target_resource_type');
            $table->unsignedBigInteger('target_resource_id');
            $table->string('protocol')->default('tcp');
            $table->unsignedSmallInteger('port');
            $table->timestamps();
        });
    }
}
