<?php

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('reuses existing projects instead of creating v5 projects', function () {
    expect(file_exists(database_path('migrations-v5/2026_06_04_050157_v5_create_projects_table.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/V5/Project.php')))->toBeFalse();
});

it('creates v5 cluster tables and lets each server belong to one cluster', function () {
    createSharedUserAndTeamTables();
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    expect(Schema::hasTable('v5_clusters'))->toBeTrue()
        ->and(Schema::hasColumns('v5_clusters', [
            'id',
            'team_id',
            'created_by_user_id',
            'name',
            'description',
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
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('v5_servers', 'cluster_id'))->toBeTrue();
});

it('creates v5 server tables in the shared database', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $migration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $migration->up();

    expect(Schema::hasTable('v5_servers'))->toBeTrue()
        ->and(Schema::hasColumns('v5_servers', [
            'id',
            'team_id',
            'cluster_id',
            'created_by_user_id',
            'private_key_id',
            'name',
            'host',
            'ssh_user',
            'ssh_port',
            'status',
            'capabilities',
            'builder_enabled',
            'builder_capacity',
            'builder_cpu_quota',
            'node_address',
            'wireguard_listen_port_override',
            'wireguard_endpoint_override',
            'uuid',
            'wireguard_management_ip',
            'wireguard_public_key',
            'container_subnets',
            'last_bootstrapped_at',
            'last_bootstrap_action',
            'last_bootstrap_status',
            'last_bootstrap_output',
            'last_bootstrap_ran_at',
            'last_status_check',
            'last_status_output',
            'last_status_checked_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('creates v5 server canvas columns for movable caddy ingress nodes', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    expect(Schema::hasColumns('v5_servers', [
        'canvas_x',
        'canvas_y',
    ]))->toBeTrue();
});

it('creates v5 server ingress columns', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    expect(Schema::hasColumns('v5_servers', [
        'ingress_type',
        'ingress_status',
    ]))->toBeTrue();
});

it('creates v5 application tables for dashboard canvas nodes', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_application_domains');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations-v5/2026_06_19_140000_v5_create_applications_table.php');
    $applicationMigration->up();

    expect(Schema::hasTable('v5_applications'))->toBeTrue()
        ->and(Schema::hasColumns('v5_applications', [
            'id',
            'team_id',
            'project_id',
            'environment_id',
            'server_id',
            'created_by_user_id',
            'name',
            'image',
            'container_name',
            'status',
            'status_message',
            'runtime_container_id',
            'mesh_namespace',
            'ingress_enabled',
            'internal_port',
            'canvas_x',
            'canvas_y',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('creates v5 application domain tables for zero or more inbound routes', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_application_domains');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations-v5/2026_06_19_140000_v5_create_applications_table.php');
    $applicationMigration->up();

    expect(Schema::hasTable('v5_application_domains'))->toBeTrue()
        ->and(Schema::hasColumns('v5_application_domains', [
            'id',
            'application_id',
            'domain',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('v5_applications', [
            'ingress_enabled',
            'internal_port',
        ]))->toBeTrue();
});

it('creates generic v5 resource connection tables', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_resource_connection_rules');
    Schema::dropIfExists('v5_resource_connections');
    Schema::dropIfExists('v5_application_domains');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations-v5/2026_06_19_140000_v5_create_applications_table.php');
    $applicationMigration->up();

    $connectionMigration = include database_path('migrations-v5/2026_06_19_142000_v5_create_resource_connections_table.php');
    $connectionMigration->up();

    expect(Schema::hasTable('v5_resource_connections'))->toBeTrue()
        ->and(Schema::hasColumns('v5_resource_connections', [
            'id',
            'team_id',
            'project_id',
            'environment_id',
            'resource_one_type',
            'resource_one_id',
            'resource_two_type',
            'resource_two_id',
            'resource_pair_key',
            'created_by_user_id',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('v5_resource_connection_rules'))->toBeTrue()
        ->and(Schema::hasColumns('v5_resource_connection_rules', [
            'id',
            'connection_id',
            'source_resource_type',
            'source_resource_id',
            'target_resource_type',
            'target_resource_id',
            'protocol',
            'port',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('keeps v5 server fields in the initial migration', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations-v5/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations-v5/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    expect(Schema::hasColumns('v5_servers', [
        'uuid',
        'ingress_type',
        'ingress_status',
        'builder_cpu_quota',
        'node_address',
        'wireguard_management_ip',
        'container_subnets',
        'canvas_x',
        'canvas_y',
        'last_bootstrap_output',
        'last_status_output',
    ]))->toBeTrue();
});

it('includes v5 tables in the dev testing schema', function () {
    $schema = file_get_contents(database_path('schema/testing-schema.sql'));

    expect($schema)->toContain('"team_id" INTEGER NOT NULL')
        ->and($schema)->toContain('"created_by_user_id" INTEGER NOT NULL')
        ->and($schema)->not->toContain('CREATE TABLE IF NOT EXISTS "v5_projects"')
        ->and($schema)->not->toContain('2026_06_04_050157_v5_create_projects_table')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_servers"')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_container_statuses"')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_applications"')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_application_domains"')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_resource_connections"')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_resource_connection_rules"')
        ->and($schema)->toContain('"domain" TEXT NOT NULL')
        ->and($schema)->toContain('"ingress_enabled" INTEGER DEFAULT false NOT NULL')
        ->and($schema)->toContain('"internal_port" INTEGER')
        ->and($schema)->toContain('"cluster_id" INTEGER')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_clusters"')
        ->and($schema)->toContain('"wireguard_interface" TEXT DEFAULT \'wg0\' NOT NULL')
        ->and($schema)->toContain('"wireguard_management_pool" TEXT DEFAULT \'100.64.0.0/16\' NOT NULL')
        ->and($schema)->toContain('"container_network_pool" TEXT DEFAULT \'10.210.0.0/16\' NOT NULL')
        ->and($schema)->toContain('"builder_timeout_secs" INTEGER NOT NULL DEFAULT \'1800\'')
        ->and($schema)->toContain('"private_key_id" INTEGER')
        ->and($schema)->toContain('"ingress_type" TEXT')
        ->and($schema)->toContain('"ingress_status" TEXT')
        ->and($schema)->toContain('"builder_cpu_quota" TEXT DEFAULT \'200%\' NOT NULL')
        ->and($schema)->toContain('"uuid" TEXT')
        ->and($schema)->toContain('"wireguard_management_ip" TEXT')
        ->and($schema)->toContain('"container_subnets" JSON')
        ->and($schema)->toContain('"canvas_x" INTEGER')
        ->and($schema)->toContain('"canvas_y" INTEGER')
        ->and($schema)->toContain('"last_bootstrap_output" TEXT')
        ->and($schema)->toContain('"last_status_output" TEXT')
        ->and($schema)->toContain('2026_06_16_130650_v5_create_servers_table')
        ->and($schema)->toContain('2026_06_19_140000_v5_create_applications_table')
        ->and($schema)->toContain('2026_06_19_142000_v5_create_resource_connections_table')
        ->and($schema)->toContain('2026_06_19_182231_create_container_statuses_table')
        ->and($schema)->not->toContain('2026_06_19_141231_add_canvas_position_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_19_173933_add_ingress_status_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_20_072818_v5_add_ingress_routing_to_applications_table')
        ->and($schema)->not->toContain('2026_06_19_150000_add_mesh_namespace_to_v5_applications_table')
        ->and($schema)->toContain('2026_06_16_130649_v5_create_clusters_table')
        ->and($schema)->not->toContain('2026_06_16_204644_v5_add_wireguard_cli_configuration_to_clusters_and_servers')
        ->and($schema)->not->toContain('2026_06_17_165112_v5_add_builder_cpu_quota_to_servers_table')
        ->and($schema)->not->toContain('2026_06_17_172845_add_status_check_fields_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_19_064539_add_bootstrap_log_fields_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_19_090217_add_uuid_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_16_131229_add_cluster_id_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_16_132000_make_v5_server_private_key_nullable')
        ->and($schema)->not->toContain('v5_hosts');
});
