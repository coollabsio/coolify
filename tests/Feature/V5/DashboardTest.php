<?php

use App\Actions\V5\Flux\ApplyFluxResourceStatusUpdate;
use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Events\V5RealtimeTestEvent;
use App\Http\Controllers\V5\DashboardController;
use App\Http\Kernel;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\V5\EnsureCurrentTeam;
use App\Http\Middleware\V5\HandleInertiaRequests;
use App\Jobs\V5BootstrapServerJob;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Cluster;
use App\Models\V5\ContainerStatus;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use App\Services\Flux\FluxHealth;
use Database\Seeders\V5DevLimaSeeder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    Config::set('app.maintenance.store', 'array');
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_resource_connection_rules');
    Schema::dropIfExists('v5_resource_connections');
    Schema::dropIfExists('v5_application_domains');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_container_statuses');
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');
    Schema::dropIfExists('private_keys');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
});

it('registers the v5 dashboard route', function () {
    expect(Route::has('v5.dashboard'))->toBeTrue()
        ->and(Route::has('v5.home'))->toBeFalse()
        ->and(Route::has('v5.selection.update'))->toBeTrue()
        ->and(Route::has('v5.clusters.index'))->toBeTrue()
        ->and(Route::has('v5.clusters.show'))->toBeTrue()
        ->and(Route::has('v5.clusters.store'))->toBeTrue()
        ->and(Route::has('v5.clusters.destroy'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.store'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.update'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.check'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.bootstrap'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.destroy'))->toBeTrue()
        ->and(Route::has('v5.applications.nginx'))->toBeTrue()
        ->and(Route::has('v5.applications.refresh'))->toBeTrue()
        ->and(Route::has('v5.applications.position'))->toBeTrue()
        ->and(Route::has('v5.applications.ingress'))->toBeTrue()
        ->and(Route::has('v5.caddy-ingresses.position'))->toBeTrue()
        ->and(Route::has('v5.applications.destroy'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.store'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.update'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.destroy'))->toBeTrue()
        ->and(Route::has('v5.realtime-test'))->toBeTrue()
        ->and(Route::has('v5.realtime-test.broadcast'))->toBeTrue()
        ->and(Route::has('v5.coolify.version'))->toBeFalse()
        ->and(Route::has('v5.coolify.bootstrap'))->toBeFalse();
});

it('uses separated v5 middleware groups', function () {
    $kernel = app(Kernel::class);
    $reflection = new ReflectionClass($kernel);
    $property = $reflection->getProperty('middlewareGroups');
    $property->setAccessible(true);
    $groups = $property->getValue($kernel);

    expect($groups)->toHaveKey('v5.web')
        ->and($groups)->toHaveKey('v5.authenticated')
        ->and($groups['v5.web'])->toContain(HandleInertiaRequests::class)
        ->and($groups['v5.web'])->not->toContain(CheckForcePasswordReset::class)
        ->and($groups['v5.web'])->not->toContain(DecideWhatToDoWithUser::class)
        ->and($groups['v5.authenticated'])->toContain('auth')
        ->and($groups['v5.authenticated'])->toContain('verified')
        ->and($groups['v5.authenticated'])->toContain(EnsureCurrentTeam::class);
});

it('reuses existing projects instead of creating v5 projects', function () {
    expect(file_exists(database_path('migrations/2026_06_04_050157_v5_create_projects_table.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/V5/Project.php')))->toBeFalse();
});

it('keeps long v5 application metadata inside the canvas card', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('overflow-hidden')
        ->and($dashboardSource)->toContain('grid grid-cols-[auto_minmax(0,1fr)]')
        ->and($dashboardSource)->toContain('truncate text-right font-medium')
        ->and($dashboardSource)->toContain('truncate text-right font-mono');
});

it('does not render v5 application status messages on dashboard cards', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->not->toContain('{application.statusMessage && (')
        ->not->toContain('{application.statusMessage}</p>');
});

it('uses the shared dialog and button components for the application ingress modal', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('<Dialog')
        ->toContain('open')
        ->toContain('<DialogContent')
        ->toContain('showCloseButton')
        ->toContain('<Button type="submit" variant="coolify"')
        ->not->toContain('>Cancel</button>')
        ->not->toContain('>Close</button>');
});

it('shows a loading state while toggling application ingress', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('savingIngressApplicationId')
        ->toContain("isApplicationIngressSaving ? 'Saving...'")
        ->toContain("isSavingIngress ? 'Saving...' : 'Enable ingress'");
});

it('generates http-only caddy routes for application ingress', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://app.example.com {')
                && ! str_contains($apps[0]['config'], 'https://')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:3000'))
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertSuccessful();
});

it('shows a dashboard refresh button next to the center button', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('onClick={() => centerOnCanvasNodes()}')
        ->toContain('onClick={() => void refreshApplications()}')
        ->toContain("isRefreshing ? 'Refreshing…' : 'Refresh state'");
});

it('subscribes the v5 dashboard canvas to automatic resource status updates', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('currentTeam = null')
        ->toContain("channel.listen('.v5.canvas.resource.updated'")
        ->toContain('setApplications((currentApplications) =>')
        ->toContain('setIngresses((currentIngresses) =>')
        ->toContain('Waiting for window.Echo before subscribing to canvas updates')
        ->toContain("channel.listen('.v5.canvas.resource.updated'")
        ->not->toContain("fetch('/v5/canvas-state'");
});

it('allows zooming the v5 dashboard canvas with buttons and pinch gestures', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('zoom: number;')
        ->toContain('MIN_CANVAS_ZOOM')
        ->toContain('MAX_CANVAS_ZOOM')
        ->toContain('PINCH_CANVAS_ZOOM_STEP')
        ->toContain('zoomCanvas(')
        ->toContain('zoomCanvas(event.deltaY < 0 ? 1 : -1, PINCH_CANVAS_ZOOM_STEP')
        ->toContain('onWheel={handleCanvasWheel}')
        ->toContain('event.ctrlKey')
        ->toContain('aria-label="Zoom out"')
        ->toContain('aria-label="Zoom in"')
        ->toContain('Math.round(viewport.zoom * 100)')
        ->toContain('scale(${viewport.zoom})');
});

it('renders draggable connector dots on v5 application canvas cards', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain("type ConnectorSide = 'top' | 'right' | 'bottom' | 'left';")
        ->toContain('application-connector')
        ->toContain('data-connector-side={side}')
        ->toContain('startConnectionDrag(event, application.id, side)')
        ->toContain('<svg className="pointer-events-none absolute inset-0 overflow-visible">')
        ->toContain('draftConnection');
});

it('keeps v5 canvas connections selectable unique and shortest-path only', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('type CanvasConnection = V5ResourceConnection;')
        ->toContain('resourceConnections: initialResourceConnections = []')
        ->toContain('useState<CanvasConnection[]>(initialResourceConnections)')
        ->toContain('selectedConnectionId')
        ->toContain('clearCanvasSelection')
        ->toContain('event.target !== event.currentTarget')
        ->toContain('connectionExists')
        ->toContain('shortestConnectionPoints')
        ->toContain("!['Backspace', 'Delete'].includes(event.key)")
        ->toContain('deletePersistedConnection(connectionId)')
        ->toContain('onClick={(event) => selectConnection(event, connection.id)}')
        ->toContain("selectedConnectionId === connection.id ? 'stroke-destructive' : 'stroke-warning'")
        ->toContain('aria-label="Select connection"')
        ->toContain('stroke="transparent"')
        ->toContain('strokeWidth={12}')
        ->toContain('data-application-card="application-card"')
        ->toContain("closest<HTMLElement>('[data-application-card]')")
        ->toContain('deleteConnection(connection.id)')
        ->toContain('Delete connection')
        ->toContain('left: (points.from.x + points.to.x) / 2')
        ->toContain('top: (points.from.y + points.to.y) / 2')
        ->toContain('id="dashboard-connection-arrow"')
        ->toContain('markerEnd={selectedConnectionId === connection.id ? \'url(#dashboard-connection-arrow)\' : undefined}')
        ->toContain('markerWidth="16"')
        ->toContain('markerHeight="16"')
        ->toContain('strokeDasharray="6 6"')
        ->toContain('persistNewConnection(pointerState.from.applicationId, targetApplicationId)')
        ->toContain('persistConnectionPorts(updatedConnection)')
        ->toContain('/v5/resource-connections')
        ->toContain('ports_by_direction: portsByDirection')
        ->toContain('connectionDirectionKey(')
        ->toContain('activeConnectionPorts(connection)')
        ->toContain('addConnectionPort(connection.id)')
        ->toContain('Number.isInteger(portNumber)')
        ->toContain('setConnectionPortInput')
        ->toContain('Allowed ports')
        ->toContain('updateConnectionDirection(')
        ->toContain('applicationDirectionLabel(')
        ->toContain('application.id.slice(0, 8)')
        ->toContain('connection.applicationIds[0]')
        ->toContain('connection.applicationIds[1]')
        ->toContain('group/application')
        ->toContain('opacity-0')
        ->toContain('group-hover/application:opacity-100');
});

it('shows v5 application connector dots after selecting a canvas card', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('selectedApplicationId')
        ->toContain('setSelectedApplicationId(application.id)')
        ->toContain('selectedApplicationId === application.id')
        ->toContain('opacity-100');
});

it('uses a larger mobile touch target for v5 application connector dots', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('size-8')
        ->toContain('md:size-3')
        ->toContain('group/connector')
        ->toContain('group-hover/connector:scale-125')
        ->toContain('<span className="size-3 rounded-full border-2 border-card bg-warning shadow ring-2 ring-background transition group-hover/connector:scale-125 group-hover/connector:bg-warning/90" />');
});

it('detects the connection drop target from pointer coordinates for mobile drags', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('connectionTargetFromPointer(event)')
        ->toContain('document.elementFromPoint(event.clientX, event.clientY)')
        ->toContain('pointer-captured mobile drags')
        ->toContain('targetApplicationId !== pointerState.from.applicationId');
});

it('creates v5 cluster tables and lets each server belong to one cluster', function () {
    createSharedUserAndTeamTables();
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $migration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations/2026_06_19_140000_v5_create_applications_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations/2026_06_19_140000_v5_create_applications_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    $applicationMigration = include database_path('migrations/2026_06_19_140000_v5_create_applications_table.php');
    $applicationMigration->up();

    $connectionMigration = include database_path('migrations/2026_06_19_142000_v5_create_resource_connections_table.php');
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

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
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

it('redirects guests to the shared login', function () {
    $this->get('/v5')
        ->assertRedirect('/login');
});

it('serves the v5 inertia shell', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    $this->withoutExceptionHandling();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Shared Team',
        'description' => 'Shared team details',
        'personal_team' => true,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('<html lang="en" class="dark">', false)
        ->assertSee('coolify-logo-dev-transparent.png', false)
        ->assertDontSee('coolify-logo.svg', false)
        ->assertSee('js/echo.js', false)
        ->assertSee('js/pusher.js', false)
        ->assertSee('window.Echo', false)
        ->assertSee('v5-app', false)
        ->assertSee('Dashboard', false)
        ->assertDontSee('Home', false)
        ->assertDontSee('v5-ready', false)
        ->assertDontSee('This page is served from Laravel through Inertia and React')
        ->assertDontSee('Bootstrap server')
        ->assertDontSee('privateKeys', false)
        ->assertSee('Running')
        ->assertSee('Flux is running.')
        ->assertDontSee('"clusters":', false)
        ->assertDontSee('cooldServers', false)
        ->assertDontSee('coold-dev')
        ->assertDontSee('Create cluster')
        ->assertDontSee('Cluster details')
        ->assertDontSee('100.64.0.1')
        ->assertDontSee('Current team')
        ->assertDontSee('Your teams')
        ->assertSee('currentTeam', false)
        ->assertSee('"currentTeam":{"id":'.$team->id, false)
        ->assertDontSee('teams', false)
        ->assertDontSee('V5 Shared Team')
        ->assertDontSee('Shared team details');
});

it('serves v5 dashboard applications as canvas nodes', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($team, 'Staging Project', 'Staging');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);

    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'abc123',
        'mesh_namespace' => 'default',
        'canvas_x' => 120,
        'canvas_y' => -80,
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $otherProject->id,
        'environment_id' => $otherEnvironment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'other-nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-other',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"applications":[', false)
        ->assertSee('"name":"nginx-test"', false)
        ->assertSee('"serverName":"edge-01"', false)
        ->assertSee('"meshNamespace":"default"', false)
        ->assertSee('"meshFqdn":"coolify-v5-nginx-1.default.coolify.internal"', false)
        ->assertSee('"canvasX":120', false)
        ->assertSee('"canvasY":-80', false)
        ->assertDontSee('other-nginx-test', false);
});

it('persists generic v5 resource connections and direction-specific ports', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $source = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-source',
        'status' => 'running',
    ]);
    $target = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-target',
        'status' => 'running',
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
            '_token' => 'test-csrf-token',
        ])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->postJson('/v5/resource-connections', [
            'resource_one' => ['type' => 'application', 'id' => $source->id],
            'resource_two' => ['type' => 'application', 'id' => $target->id],
        ])
        ->assertCreated()
        ->assertJsonPath('connection.applicationIds.0', (string) $source->id)
        ->assertJsonPath('connection.applicationIds.1', (string) $target->id);

    $connectionId = $response->json('connection.id');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connectionId}", [
            'ports_by_direction' => [
                "{$source->id}->{$target->id}" => [80],
                "{$target->id}->{$source->id}" => [443],
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath("connection.portsByDirection.{$source->id}->{$target->id}.0", '80')
        ->assertJsonPath("connection.portsByDirection.{$target->id}->{$source->id}.0", '443');

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"resourceConnections":[', false)
        ->assertSee("\"id\":\"{$connectionId}\"", false)
        ->assertSee("\"{$source->id}->{$target->id}\":[\"80\"]", false)
        ->assertSee("\"{$target->id}->{$source->id}\":[\"443\"]", false);
});

it('syncs v5 resource connection ports through flux firewall primitives', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    $this->withoutExceptionHandling();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.10',
        'capabilities' => [],
    ]);
    $source = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
        'mesh_namespace' => 'default',
        'status' => 'running',
    ]);
    $target = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'postgres',
        'image' => 'docker.io/library/postgres:16',
        'container_name' => 'coolify-v5-postgres',
        'mesh_namespace' => 'default',
        'status' => 'running',
    ]);

    $connection = ResourceConnection::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'resource_one_type' => $source->getMorphClass(),
        'resource_one_id' => $source->id,
        'resource_two_type' => $target->getMorphClass(),
        'resource_two_id' => $target->id,
        'resource_pair_key' => "application:{$source->id}|application:{$target->id}",
        'created_by_user_id' => $user->id,
    ]);

    $firewallRuleId = "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:5432";

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with('100.64.0.10', [
            'id' => $firewallRuleId,
            'namespace' => 'default',
            'src' => 'coolify-v5-api',
            'dst' => 'coolify-v5-postgres',
            'proto' => 'tcp',
            'port' => 5432,
        ])
        ->andReturn('rule-api-postgres');
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->with('100.64.0.10', $firewallRuleId)
        ->andReturn('Firewall rule removed.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->id}", [
            'ports_by_direction' => [
                "{$source->id}->{$target->id}" => [5432],
            ],
        ])
        ->assertSuccessful();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->deleteJson("/v5/resource-connections/{$connection->id}")
        ->assertNoContent();
});

it('serves enabled v5 caddy ingress servers as canvas nodes', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    createV5ProjectWithEnvironment($team, 'Production Project', 'Production');

    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'running',
        'capabilities' => ['ingress'],
        'canvas_x' => -160,
        'canvas_y' => 240,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-02',
        'host' => '203.0.113.22',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'exited',
        'capabilities' => ['ingress'],
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-worker-01',
        'host' => '203.0.113.21',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"caddyIngresses":[', false)
        ->assertSee('"name":"edge-ingress-01"', false)
        ->assertSee('"host":"203.0.113.20"', false)
        ->assertSee('"type":"caddy"', false)
        ->assertSee('"status":"running"', false)
        ->assertSee('"name":"edge-ingress-02"', false)
        ->assertSee('"status":"exited"', false)
        ->assertSee('"canvasX":-160', false)
        ->assertSee('"canvasY":240', false);
});

it('creates an nginx v5 application on the first installed team server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: "nginx-container-id\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertCreated()
        ->assertJsonPath('application.name', 'nginx-test')
        ->assertJsonPath('application.image', 'docker.io/library/nginx:alpine')
        ->assertJsonPath('application.status', 'running')
        ->assertJsonPath('application.serverName', 'edge-01')
        ->assertJsonPath('application.meshNamespace', 'default')
        ->assertJsonPath('application.canvasX', 0)
        ->assertJsonPath('application.canvasY', 0);

    expect(V5Application::query()
        ->where('team_id', $team->id)
        ->where('project_id', $project->id)
        ->where('environment_id', $environment->id)
        ->where('name', 'nginx-test')
        ->where('status', 'running')
        ->where('runtime_container_id', 'nginx-container-id')
        ->exists())->toBeTrue();
});

it('creates an nginx v5 application on the selected team server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);
    $selectedServer = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: "nginx-container-id\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'server_id' => $selectedServer->id,
        ])
        ->assertCreated()
        ->assertJsonPath('application.serverName', 'edge-02');

    expect(V5Application::query()
        ->where('server_id', $selectedServer->id)
        ->where('runtime_container_id', 'nginx-container-id')
        ->exists())->toBeTrue();
});

it('places a new nginx v5 application next to existing canvas nodes', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-existing',
        'status' => 'running',
        'status_message' => 'Running.',
        'mesh_namespace' => 'default',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    Process::fake([
        '*' => Process::result(output: "nginx-container-id\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertCreated()
        ->assertJsonPath('application.canvasX', 352)
        ->assertJsonPath('application.canvasY', 0);
});

it('marks an nginx v5 application failed when the launch command fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(errorOutput: 'podman failed', exitCode: 1),
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertUnprocessable()
        ->assertJsonPath('application.status', 'failed')
        ->assertJsonPath('application.statusMessage', 'podman failed');
});

it('does not create an nginx v5 application on another teams selected server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$otherUser, $otherTeam] = createV5UserWithTeam('other@example.com');
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($otherTeam, 'Other SSH Key');
    $otherServer = V5Server::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'private_key_id' => $privateKey->id,
        'name' => 'other-edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'server_id' => $otherServer->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Add a v5 server before deploying nginx.');

    expect(V5Application::query()->count())->toBe(0);
});

it('does not create an nginx v5 application without a team server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Add a v5 server before deploying nginx.');

    expect(V5Application::query()->count())->toBe(0);
});

it('deletes a v5 application for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->id}")
        ->assertNoContent();

    expect(V5Application::query()->whereKey($application->id)->exists())->toBeFalse();
});

it('stops and deletes the nginx container before deleting a v5 application', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'runtime_container_id' => 'nginx-container-id',
    ]);

    Process::fake([
        '*' => Process::result(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->id}")
        ->assertNoContent();

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return is_string($command)
            && str_contains($command, '203.0.113.10')
            && str_contains($command, 'podman rm -f')
            && str_contains($command, 'coolify-v5-nginx-1');
    });
    expect(V5Application::query()->whereKey($application->id)->exists())->toBeFalse();
});

it('does not delete another teams v5 application', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$otherUser, $otherTeam] = createV5UserWithTeam();
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($otherTeam, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $application = V5Application::query()->create([
        'team_id' => $otherTeam->id,
        'project_id' => $otherProject->id,
        'environment_id' => $otherEnvironment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->id}")
        ->assertNotFound();

    expect(V5Application::query()->whereKey($application->id)->exists())->toBeTrue();
});

it('updates v5 application canvas position for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/position", [
            'canvas_x' => 320,
            'canvas_y' => -160,
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.canvasX', 320)
        ->assertJsonPath('application.canvasY', -160);

    expect($application->refresh()->canvas_x)->toBe(320)
        ->and($application->canvas_y)->toBe(-160);
});

it('updates v5 caddy ingress canvas position for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'canvas_x' => -352,
        'canvas_y' => 0,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/caddy-ingresses/{$server->id}/position", [
            'canvas_x' => -160,
            'canvas_y' => 240,
        ])
        ->assertSuccessful()
        ->assertJsonPath('caddyIngress.canvasX', -160)
        ->assertJsonPath('caddyIngress.canvasY', 240);

    expect($server->refresh()->canvas_x)->toBe(-160)
        ->and($server->canvas_y)->toBe(240);
});

it('applies flux application status updates to the database and broadcasts to the team canvas', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'new-nginx-container-id',
        'status' => 'exited',
        'status_message' => 'Status received from coold through flux.',
    ]);

    expect($resource)->toBeInstanceOf(V5Application::class)
        ->and($application->refresh()->status)->toBe('exited')
        ->and($application->status_message)->toBe('Status received from coold through flux.')
        ->and($application->runtime_container_id)->toBe('new-nginx-container-id');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('maps generic flux container status updates to v5 applications', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'container',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
        'status_message' => 'Container state received from coold.',
    ]);

    expect($resource)->toBeInstanceOf(V5Application::class)
        ->and($application->refresh()->status)->toBe('exited')
        ->and($application->status_message)->toBe('Container state received from coold.');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('applies flux ingress server status updates to the database and broadcasts cluster plus canvas updates', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Cluster',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
        'message' => 'coold heartbeat timed out.',
    ]);

    expect($resource)->toBeInstanceOf(V5Server::class)
        ->and($server->refresh()->status)->toBe('unreachable')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('coold heartbeat timed out.')
        ->and($server->last_status_checked_at)->not->toBeNull();

    Event::assertDispatched(V5ClusterUpdated::class, fn (V5ClusterUpdated $event) => $event->teamId === $team->id
        && $event->clusterId === $cluster->id);
    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('applies flux caddy ingress container status updates without changing server install status', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'running',
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'caddy_ingress',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-caddy',
        'status' => 'exited',
        'message' => 'Caddy container exited.',
    ]);

    expect($resource)->toBeInstanceOf(V5Server::class)
        ->and($server->refresh()->status)->toBe('installed')
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('exited')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('Caddy container exited.')
        ->and($server->last_status_checked_at)->not->toBeNull();

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('accepts flux status updates for non coolify managed containers', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    $this
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'container',
            'host_id' => '100.64.0.5',
            'container_id' => 'external-container-id',
            'container_name' => 'external-container',
            'status' => 'running',
        ], [
            'Authorization' => 'Bearer test-flux-token',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect(ContainerStatus::query()
        ->where('container_id', 'external-container-id')
        ->where('container_name', 'external-container')
        ->where('status', 'running')
        ->exists())->toBeTrue();
});

it('rejects flux resource status http updates without the shared token', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');

    $this
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'status' => 'running',
        ])
        ->assertUnauthorized();
});

it('accepts flux resource status http updates and stores them in the database', function () {
    createSharedUserAndTeamTables();
    Config::set('flux.laravel_api_token', 'test-flux-token');

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'starting',
        'status_message' => 'Container starting.',
        'runtime_container_id' => 'old-container-id',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'host_id' => '100.64.0.5',
            'container_name' => 'coolify-v5-nginx-1',
            'container_id' => 'new-container-id',
            'status' => 'running',
            'status_message' => 'Status received from coold through flux.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect($application->refresh()->status)->toBe('running')
        ->and($application->status_message)->toBe('Status received from coold through flux.')
        ->and($application->runtime_container_id)->toBe('new-container-id');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('configures flux resource status updates for local http instead of redis', function () {
    $configSource = file_get_contents(config_path('flux.php'));

    expect($configSource)
        ->toContain('COOLIFY_FLUX_LARAVEL_API_TOKEN')
        ->not->toContain('APP_KEY')
        ->not->toContain('COOLIFY_FLUX_RESOURCE_STATUS_CHANNEL')
        ->not->toContain('resource_status_channel');
});

it('broadcasts v5 canvas resource updates when application state changes', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $application->update([
        'status' => 'exited',
        'status_message' => 'Container stopped.',
    ]);

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('broadcasts v5 cluster and canvas updates when ingress server state changes', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Cluster',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server->update(['status' => 'unreachable']);

    Event::assertDispatched(V5ClusterUpdated::class, fn (V5ClusterUpdated $event) => $event->teamId === $team->id
        && $event->clusterId === $cluster->id);
    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('refreshes v5 application state from flux container inventory', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listContainers')
            ->once()
            ->with('100.64.0.5')
            ->andReturn([
                [
                    'id' => 'nginx-container-id',
                    'name' => 'coolify-v5-nginx-1',
                    'image' => 'docker.io/library/nginx:alpine',
                    'state' => 'exited',
                    'networks' => ['coolify-default-mesh'],
                ],
            ]);
    });

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/refresh')
        ->assertSuccessful()
        ->assertJsonPath('applications.0.id', (string) $application->id)
        ->assertJsonPath('applications.0.status', 'exited')
        ->assertJsonPath('applications.0.statusMessage', 'Container state refreshed from coold.');

    expect($application->refresh()->status)->toBe('exited')
        ->and($application->status_message)->toBe('Container state refreshed from coold.');
});

it('refreshes v5 caddy ingress state from flux container inventory', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'running',
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.6',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listContainers')
            ->once()
            ->with('100.64.0.6')
            ->andReturn([
                [
                    'id' => 'caddy-container-id',
                    'name' => 'coolify-v5-caddy',
                    'image' => 'docker.io/library/caddy:2-alpine',
                    'state' => 'exited',
                ],
            ]);
    });

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/refresh')
        ->assertSuccessful()
        ->assertJsonPath('caddyIngresses.0.id', (string) $server->id)
        ->assertJsonPath('caddyIngresses.0.type', 'caddy')
        ->assertJsonPath('caddyIngresses.0.status', 'exited');

    expect($server->refresh()->status)->toBe('installed')
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('exited')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('Caddy ingress state refreshed from coold.');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('broadcasts v5 cluster updates when bootstrap state changes', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Queue::fake();
    Event::fake([V5ClusterUpdated::class]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$server->id}/bootstrap")
        ->assertAccepted();

    Event::assertDispatched(V5ClusterUpdated::class, fn ($event): bool => $event->clusterId === $cluster->id
        && $event->teamId === $team->id);

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    expect(Event::dispatched(V5ClusterUpdated::class)->count())->toBeGreaterThanOrEqual(2);
});

it('returns fresh v5 cluster bootstrap state for realtime fallback refreshes', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrap_action' => 'bootstrap',
        'last_bootstrap_status' => 'running',
        'last_bootstrap_output' => 'Starting Coolify CLI bootstrap for prod-01...',
        'last_bootstrap_ran_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->id}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.id', (string) $cluster->id)
        ->assertJsonPath('cluster.servers.0.id', (string) $server->id)
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'running')
        ->assertJsonPath('cluster.servers.0.lastBootstrapOutput', 'Starting Coolify CLI bootstrap for prod-01...');
});

it('broadcasts v5 cluster updates immediately with an explicit echo event name', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));
    $event = new V5ClusterUpdated(1, 1);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('v5.cluster.updated')
        ->and($clustersPage)->toContain("listen('.v5.cluster.updated'")
        ->and($clustersPage)->toContain('Waiting for window.Echo before subscribing to cluster updates');
});

it('serves a v5 realtime test page for manual websocket diagnostics', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $page = file_get_contents(resource_path('js/v5/Pages/RealtimeTest.tsx'));

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/realtime-test')
        ->assertSuccessful()
        ->assertSee('RealtimeTest', false)
        ->assertSee((string) $team->id, false);

    expect($page)
        ->toContain("listen('.v5.realtime.test'")
        ->toContain('Subscribing to private-')
        ->toContain('Broadcast event');
});

it('broadcasts manual v5 realtime test events immediately', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    Event::fake([V5RealtimeTestEvent::class]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/realtime-test', [
            'message' => 'hello socket',
        ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Realtime test event broadcasted.');

    Event::assertDispatched(V5RealtimeTestEvent::class, fn (V5RealtimeTestEvent $event): bool => $event->teamId === $team->id
        && $event->message === 'hello socket'
        && $event->broadcastAs() === 'v5.realtime.test'
        && $event instanceof ShouldBroadcastNow);
});

it('serves the production v5 favicon outside local environments', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('coolify-logo.svg', false)
        ->assertDontSee('coolify-logo-dev-transparent.png', false);
});

it('shows v5 clusters with their servers on the cluster page', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/clusters')
        ->assertSuccessful()
        ->assertSee('Clusters', false)
        ->assertSee('"clusters":[', false)
        ->assertSee('"privateKeys":[', false)
        ->assertSee('"name":"Lima Key"', false)
        ->assertSee('"name":"Development-Lima"', false)
        ->assertSee('"serversCount":1', false)
        ->assertSee('"name":"coold-dev"', false)
        ->assertSee('"host":"lima-coold-dev"', false)
        ->assertDontSee('"sshUser"', false)
        ->assertDontSee('"sshPort"', false)
        ->assertSee('"builderEnabled":true', false)
        ->assertSee('"builderCapacity":2', false)
        ->assertSee('"wireguardInterface":"wg0"', false)
        ->assertSee('"wireguardManagementPool":"100.64.0.0\\/16"', false)
        ->assertSee('"containerNetworkPool":"10.210.0.0\\/16"', false)
        ->assertSee('"namespaces":["default"]', false)
        ->assertSee('"defaultDenyContainers":true', false)
        ->assertSee('"cooldVersion":"nightly"', false)
        ->assertSee('"corrosionVersion":"v1.0.0"', false)
        ->assertSee('"builderCpuQuota":"200%"', false)
        ->assertSee('"builderMemoryMax":"2G"', false)
        ->assertSee('"builderTimeoutSecs":1800', false)
        ->assertSee('"lastCliStatus":null', false)
        ->assertSee('"privateKeyName":"Lima Key"', false)
        ->assertSee('"nodeAddress":null', false)
        ->assertSee('"wireguardManagementIp":null', false)
        ->assertSee('"containerSubnets":[]', false)
        ->assertSee('"lastBootstrappedAt":"', false);
});

it('creates a v5 cluster for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => 'Primary production cluster.',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.name', 'Production Mesh')
        ->assertJsonPath('cluster.description', 'Primary production cluster.')
        ->assertJsonPath('cluster.wireguardInterface', 'wg0')
        ->assertJsonPath('cluster.wireguardManagementPool', '100.64.0.0/16')
        ->assertJsonPath('cluster.wireguardListenPort', 51820)
        ->assertJsonPath('cluster.containerNetworkPool', '10.210.0.0/16')
        ->assertJsonPath('cluster.containerNetworkPrefix', 24)
        ->assertJsonPath('cluster.namespaces', ['default'])
        ->assertJsonPath('cluster.defaultDenyContainers', true)
        ->assertJsonPath('cluster.cooldVersion', 'nightly')
        ->assertJsonPath('cluster.corrosionVersion', 'v1.0.0')
        ->assertJsonPath('cluster.corrosionGossipPort', 8787)
        ->assertJsonPath('cluster.corrosionApiPort', 8080)
        ->assertJsonPath('cluster.builderEnabled', true)
        ->assertJsonPath('cluster.builderCapacity', 2)
        ->assertJsonPath('cluster.builderCpuQuota', '200%')
        ->assertJsonPath('cluster.builderMemoryMax', '2G')
        ->assertJsonPath('cluster.builderTimeoutSecs', 1800)
        ->assertJsonPath('cluster.lastCliAction', null)
        ->assertJsonPath('cluster.lastCliStatus', null)
        ->assertJsonPath('cluster.lastCliSummary', null)
        ->assertJsonPath('cluster.lastCliRanAt', null)
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(Cluster::query()
        ->where('team_id', $team->id)
        ->where('created_by_user_id', $user->id)
        ->where('name', 'Production Mesh')
        ->where('description', 'Primary production cluster.')
        ->where('wireguard_interface', 'wg0')
        ->where('wireguard_management_pool', '100.64.0.0/16')
        ->where('container_network_pool', '10.210.0.0/16')
        ->exists())->toBeTrue();
});

it('creates a v5 cluster with advanced cli configuration', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Custom Mesh',
            'description' => null,
            'wireguard_interface' => 'wg-prod',
            'wireguard_management_pool' => '100.65.0.0/16',
            'wireguard_listen_port' => 51830,
            'container_network_pool' => '10.211.0.0/16',
            'container_network_prefix' => 25,
            'namespaces' => ['default', 'preview'],
            'default_deny_containers' => false,
            'coold_version' => 'v0.2.0',
            'corrosion_version' => 'v1.1.0',
            'corrosion_gossip_port' => 8788,
            'corrosion_api_port' => 8081,
            'builder_enabled' => true,
            'builder_capacity' => 4,
            'builder_cpu_quota' => '400%',
            'builder_memory_max' => '4G',
            'builder_timeout_secs' => 2400,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.wireguardInterface', 'wg-prod')
        ->assertJsonPath('cluster.wireguardManagementPool', '100.65.0.0/16')
        ->assertJsonPath('cluster.wireguardListenPort', 51830)
        ->assertJsonPath('cluster.containerNetworkPool', '10.211.0.0/16')
        ->assertJsonPath('cluster.containerNetworkPrefix', 25)
        ->assertJsonPath('cluster.namespaces', ['default', 'preview'])
        ->assertJsonPath('cluster.defaultDenyContainers', false)
        ->assertJsonPath('cluster.cooldVersion', 'v0.2.0')
        ->assertJsonPath('cluster.corrosionVersion', 'v1.1.0')
        ->assertJsonPath('cluster.builderCapacity', 4)
        ->assertJsonPath('cluster.builderCpuQuota', '400%')
        ->assertJsonPath('cluster.builderMemoryMax', '4G')
        ->assertJsonPath('cluster.builderTimeoutSecs', 2400);
});

it('validates advanced v5 cluster cli configuration', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Broken Mesh',
            'wireguard_management_pool' => 'not-a-cidr',
            'container_network_pool' => '10.211.0.0',
            'wireguard_listen_port' => 70000,
            'namespaces' => ['Default'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'wireguard_management_pool',
            'container_network_pool',
            'wireguard_listen_port',
            'namespaces.0',
        ]);
});

it('requires positive v5 cluster builder capacity when builders are enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Zero Builder Mesh',
            'builder_enabled' => true,
            'builder_capacity' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});

it('adds a v5 server to a cluster for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'builder_enabled' => true,
        'builder_capacity' => 3,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_id' => $privateKey->id,
            'node_address' => '203.0.113.10',
            'builder_enabled' => true,
            'builder_capacity' => 3,
            'wireguard_listen_port_override' => 51821,
            'wireguard_endpoint_override' => 'prod-01.example.com:51821',
            'coold_version' => 'v9.9.9',
            'corrosion_version' => 'v8.8.8',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.serversCount', 1)
        ->assertJsonPath('cluster.servers.0.name', 'prod-01')
        ->assertJsonPath('cluster.servers.0.host', '203.0.113.10')
        ->assertJsonMissingPath('cluster.servers.0.sshUser')
        ->assertJsonMissingPath('cluster.servers.0.sshPort')
        ->assertJsonMissingPath('cluster.servers.0.cooldVersion')
        ->assertJsonMissingPath('cluster.servers.0.corrosionVersion')
        ->assertJsonPath('cluster.servers.0.privateKeyName', 'Production SSH Key')
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.nodeAddress', '203.0.113.10')
        ->assertJsonPath('cluster.servers.0.builderEnabled', true)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 3)
        ->assertJsonPath('cluster.servers.0.builderCpuQuota', '200%')
        ->assertJsonPath('cluster.servers.0.ingressEnabled', false)
        ->assertJsonPath('cluster.servers.0.ingressType', null)
        ->assertJsonPath('cluster.servers.0.capabilities', [])
        ->assertJsonPath('cluster.servers.0.wireguardListenPortOverride', 51821)
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'prod-01.example.com:51821')
        ->assertJsonPath('cluster.servers.0.wireguardManagementIp', null)
        ->assertJsonPath('cluster.servers.0.containerSubnets', []);

    expect(V5Server::query()
        ->where('team_id', $team->id)
        ->where('cluster_id', $cluster->id)
        ->where('created_by_user_id', $user->id)
        ->where('name', 'prod-01')
        ->where('host', '203.0.113.10')
        ->where('ssh_user', 'root')
        ->where('ssh_port', 22)
        ->where('private_key_id', $privateKey->id)
        ->where('node_address', '203.0.113.10')
        ->exists())->toBeTrue();

    expect(V5Server::query()->where('name', 'prod-01')->first()->capabilities)
        ->toBe([]);
});

it('adds a v5 server with caddy ingress enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'edge-01',
            'host' => '203.0.113.20',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_id' => $privateKey->id,
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy')
        ->assertJsonPath('cluster.servers.0.capabilities', ['ingress']);

    $server = V5Server::query()->where('name', 'edge-01')->first();

    expect($server->capabilities)->toBe(['ingress'])
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->isIngress())->toBeTrue();
});

it('keeps added v5 server builder capacity when builder is disabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'builder_enabled' => true,
        'builder_capacity' => 3,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_id' => $privateKey->id,
            'builder_enabled' => false,
            'builder_capacity' => 3,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 3)
        ->assertJsonPath('cluster.servers.0.capabilities', []);

    $server = V5Server::query()->where('name', 'prod-01')->first();

    expect($server->builder_enabled)->toBeFalse()
        ->and($server->builder_capacity)->toBe(3)
        ->and($server->capabilities)->toBe([]);
});

it('requires positive v5 server builder capacity when builder is enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_id' => $privateKey->id,
            'builder_enabled' => true,
            'builder_capacity' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});

it('defaults dev Lima wireguard overrides for host docker internal servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Testing Host Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'coolify-naked-test',
            'host' => 'host.docker.internal',
            'ssh_user' => 'root',
            'ssh_port' => 60003,
            'private_key_id' => $privateKey->id,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.wireguardListenPortOverride', 51823)
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'host.lima.internal:51823');
});

it('rejects adding a v5 server to another teams cluster', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $cluster = Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $user->id,
        'name' => 'Other Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
        ])
        ->assertForbidden();
});

it('validates v5 server creation input', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => '',
            'host' => '',
            'ssh_user' => '',
            'ssh_port' => 70000,
            'private_key_id' => null,
            'wireguard_listen_port_override' => 70000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
            'host',
            'ssh_user',
            'ssh_port',
            'private_key_id',
            'wireguard_listen_port_override',
        ]);
});

it('rejects private keys from another team when adding a v5 server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherPrivateKey = createV5PrivateKey($otherTeam, 'Other SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_id' => $otherPrivateKey->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['private_key_id']);
});

it('checks v5 server ssh status without storing diagnostic output', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Process::fake([
        '*' => Process::result(output: "SSH connection OK\nprod-01\nLinux aarch64\n/usr/bin/docker\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$server->id}/check")
        ->assertSuccessful()
        ->assertJsonPath('status', 'reachable')
        ->assertJsonPath('output', "SSH connection OK\nprod-01\nLinux aarch64\n/usr/bin/docker")
        ->assertJsonPath('checkedAt', fn (?string $value) => $value !== null);

    Process::assertRan(fn ($process) => str_contains(json_encode($process->command), '203.0.113.10'));
    Process::assertRan(fn ($process) => in_array('LogLevel=ERROR', $process->command, true));

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_status_check)->toBeNull()
        ->and($server->last_status_output)->toBeNull()
        ->and($server->last_status_checked_at)->toBeNull();
});

it('writes quiet ssh options for v5 bootstrap ssh config', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
    ]);
    $server->load('privateKey');

    $tempDirectory = storage_path('framework/testing/v5_bootstrap_'.str()->random(8));
    mkdir($tempDirectory, 0700, true);

    try {
        $controller = app(DashboardController::class);
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('writeBootstrapSshConfig');
        $method->setAccessible(true);

        $sshConfigLocation = $method->invoke($controller, collect([$server]), $tempDirectory);
        $config = file_get_contents($sshConfigLocation);

        expect($config)->toContain('  LogLevel ERROR')
            ->and($config)->toContain('  UserKnownHostsFile /dev/null')
            ->and($config)->toContain('  StrictHostKeyChecking no');
    } finally {
        collect(scandir($tempDirectory) ?: [])
            ->reject(fn (string $file) => in_array($file, ['.', '..'], true))
            ->each(fn (string $file) => @unlink($tempDirectory.'/'.$file));
        @rmdir($tempDirectory);
    }
});

it('bootstraps a single v5 server with the Coolify CLI', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'namespaces' => ['default', 'preview'],
        'container_network_pool' => '10.211.0.0/16',
        'container_network_prefix' => 25,
        'wireguard_management_pool' => '100.65.0.0/16',
        'wireguard_interface' => 'wg-prod',
        'wireguard_listen_port' => 51830,
        'coold_version' => 'v0.2.0',
        'corrosion_version' => 'v1.1.0',
        'corrosion_gossip_port' => 8788,
        'corrosion_api_port' => 8081,
        'builder_enabled' => true,
        'builder_capacity' => 4,
        'builder_cpu_quota' => '400%',
        'builder_memory_max' => '4G',
        'builder_timeout_secs' => 2400,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 2222,
        'status' => 'added',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 4,
        'wireguard_listen_port_override' => 51831,
        'wireguard_endpoint_override' => 'prod-01.example.com:51831',
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$server->id}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('message', 'Bootstrap queued.')
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.lastBootstrapAction', 'bootstrap')
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'queued')
        ->assertJsonPath('cluster.servers.0.lastBootstrapOutput', 'Queued Coolify bootstrap for prod-01.');

    Queue::assertPushed(V5BootstrapServerJob::class);

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    Process::assertRan(function ($process): bool {
        $command = $process->command;
        $node = cliFlagValue($command, '--nodes');

        return $command[0] === '/tmp/coolify'
            && in_array('init', $command, true)
            && in_array('bootstrap', $command, true)
            && in_array('--ssh-config', $command, true)
            && is_string($node)
            && str_starts_with($node, 'v5-server-')
            && cliFlagValue($command, '--namespaces') === 'default,preview'
            && cliFlagValue($command, '--container-pool') === '10.211.0.0/16'
            && cliFlagValue($command, '--wg-mgmt-pool') === '100.65.0.0/16'
            && cliFlagValue($command, '--wg-interface') === 'wg-prod'
            && cliFlagValue($command, '--coold-version') === 'v0.2.0'
            && cliFlagValue($command, '--corrosion-version') === 'v1.1.0'
            && cliFlagValue($command, '--wg-listen-port-overrides') === $node.'=51831'
            && cliFlagValue($command, '--wg-endpoint-overrides') === $node.'=prod-01.example.com:51831'
            && ! in_array('--enable-builder', $command, true)
            && in_array('--yes', $command, true)
            && ! in_array('--new-nodes', $command, true);
    });

    $cluster->refresh();

    expect($server->status)->toBe('installed')
        ->and($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->last_bootstrap_output)->toBe('Bootstrap completed')
        ->and($server->last_bootstrapped_at)->not->toBeNull();
});

it('does not run a macOS development Coolify CLI binary from Docker during v5 server bootstrap', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/usr/local/bin/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$server->id}/bootstrap")
        ->assertAccepted();

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertRan(fn ($process): bool => $process->command[0] === '/usr/local/bin/coolify');
});

it('keeps a v5 server added when Coolify CLI bootstrap fails', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$server->id}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.lastBootstrappedAt', null)
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'queued');

    Process::fake([
        '*' => Process::result(errorOutput: "bootstrap failed\n", exitCode: 1),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toBe('bootstrap failed')
        ->and($server->last_bootstrapped_at)->toBeNull();
});

it('extends a v5 cluster when bootstrapping a new server', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $oldPrivateKey = createV5PrivateKey($team, 'Old SSH Key');
    $newPrivateKey = createV5PrivateKey($team, 'New SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'coold_version' => 'v0.3.0',
        'corrosion_version' => 'v1.2.0',
    ]);
    $oldServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $oldPrivateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'ubuntu',
        'ssh_port' => 2222,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now()->subDay(),
    ]);
    $newServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $newPrivateKey->id,
        'name' => 'prod-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->id}/servers/{$newServer->id}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('cluster.servers.1.lastBootstrapAction', 'extend')
        ->assertJsonPath('cluster.servers.1.lastBootstrapStatus', 'queued');

    Process::fake([
        '*' => Process::result(output: "Extend completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $newServer->id))->handle();

    Process::assertRan(function ($process) use ($oldServer, $newServer): bool {
        $command = $process->command;
        $oldNode = "v5-server-{$oldServer->uuid}";
        $newNode = "v5-server-{$newServer->uuid}";

        return in_array('extend', $command, true)
            && in_array("{$oldNode},{$newNode}", $command, true)
            && in_array('--new-nodes', $command, true)
            && in_array($newNode, $command, true)
            && in_array('--ssh-config', $command, true)
            && cliFlagValue($command, '--coold-version') === 'v0.3.0'
            && cliFlagValue($command, '--corrosion-version') === 'v1.2.0';
    });

    $oldServer->refresh();
    $newServer->refresh();

    expect($oldServer->status)->toBe('installed')
        ->and($newServer->status)->toBe('installed')
        ->and($newServer->last_bootstrap_status)->toBe('succeeded')
        ->and($newServer->last_bootstrap_output)->toBe('Extend completed')
        ->and($newServer->last_bootstrapped_at)->not->toBeNull();
});

it('adopts a re-added v5 server that is already bootstrapped for the same cluster', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'cluster_id' => $cluster->id,
            'server_uuid' => 'server-previous-uuid',
            'wireguard_management_ip' => '100.64.0.10',
            'wireguard_public_key' => 'public-key',
            'container_subnets' => ['default' => '10.210.0.0/24'],
        ], JSON_THROW_ON_ERROR)."\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === '/tmp/coolify');

    $server->refresh();

    expect($server->status)->toBe('installed')
        ->and($server->uuid)->toBe('server-previous-uuid')
        ->and($server->wireguard_management_ip)->toBe('100.64.0.10')
        ->and($server->wireguard_public_key)->toBe('public-key')
        ->and($server->container_subnets)->toBe(['default' => '10.210.0.0/24'])
        ->and($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->last_bootstrap_output)->toBe('Adopted existing Coolify bootstrap state for this cluster.')
        ->and($server->last_bootstrapped_at)->not->toBeNull();
});

it('blocks bootstrapping a v5 server that belongs to another cluster', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'cluster_id' => $cluster->id + 100,
            'server_uuid' => 'server-other-uuid',
        ], JSON_THROW_ON_ERROR)."\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === '/tmp/coolify');

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toContain('already bootstrapped for another cluster')
        ->and($server->last_bootstrapped_at)->toBeNull();
});

it('deletes an unbootstrapped v5 server from a cluster', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->id}/servers/{$server->id}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('deletes a bootstrapped v5 server from a cluster', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->id}/servers/{$server->id}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('updates editable v5 server builder details without changing networking', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '100%',
        'node_address' => '10.0.0.10',
        'wireguard_listen_port_override' => 51821,
        'wireguard_endpoint_override' => 'prod-01.example.com:51821',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => true,
            'builder_capacity' => 5,
            'builder_cpu_quota' => '350%',
            'host' => '198.51.100.99',
            'ssh_user' => 'admin',
            'ssh_port' => 2222,
            'node_address' => '10.0.0.99',
            'wireguard_endpoint_override' => 'changed.example.com:51821',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', true)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 5)
        ->assertJsonPath('cluster.servers.0.builderCpuQuota', '350%')
        ->assertJsonPath('cluster.servers.0.ingressEnabled', false)
        ->assertJsonPath('cluster.servers.0.host', '203.0.113.10')
        ->assertJsonMissingPath('cluster.servers.0.sshUser')
        ->assertJsonMissingPath('cluster.servers.0.sshPort')
        ->assertJsonPath('cluster.servers.0.nodeAddress', '10.0.0.10')
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'prod-01.example.com:51821');

    $server->refresh();

    expect($server->builder_enabled)->toBeTrue()
        ->and($server->builder_capacity)->toBe(5)
        ->and($server->builder_cpu_quota)->toBe('350%')
        ->and($server->capabilities)->toBe([])
        ->and($server->host)->toBe('203.0.113.10')
        ->and($server->ssh_user)->toBe('root')
        ->and($server->ssh_port)->toBe(22)
        ->and($server->node_address)->toBe('10.0.0.10')
        ->and($server->wireguard_endpoint_override)->toBe('prod-01.example.com:51821');
});

it('updates editable v5 server caddy ingress capability independently from builder', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => false,
            'builder_capacity' => 2,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy')
        ->assertJsonPath('cluster.servers.0.capabilities', ['ingress']);

    $server->refresh();

    expect($server->capabilities)->toBe(['ingress'])
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->builder_enabled)->toBeFalse()
        ->and($server->isIngress())->toBeTrue();
});

it('rejects application ingress when server ingress is disabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'app-01',
        'host' => '203.0.113.21',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.11',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'private-app',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-private-app',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldNotReceive('applyIngress');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Enable ingress on the server before enabling app ingress.');

    expect($application->refresh()->ingress_enabled)->toBeFalse()
        ->and($application->domains()->count())->toBe(0);
});

it('enables application ingress without publishing domains by default', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'kept.example.com',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            []
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => false,
            'internal_port' => 8080,
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.ingressEnabled', false)
        ->assertJsonPath('application.internalPort', 8080)
        ->assertJsonPath('application.domains.0', 'kept.example.com');

    expect($application->refresh()->ingress_enabled)->toBeFalse();
});

it('validates application ingress domains', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldNotReceive('applyIngress');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['https://bad.example.com'],
        ])
        ->assertUnprocessable()
        ->assertInvalid(['domains.0']);

    expect($application->refresh()->ingress_enabled)->toBeFalse();
});

it('enables application ingress with explicit domains and port', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://app.example.com {')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:3000'))
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.ingressEnabled', true)
        ->assertJsonPath('application.internalPort', 3000)
        ->assertJsonPath('application.domains.0', 'app.example.com');

    expect($application->refresh()->ingress_enabled)->toBeTrue()
        ->and($application->internal_port)->toBe(3000)
        ->and($application->domains()->pluck('domain')->all())->toBe(['app.example.com']);
});

it('returns flux error details when application ingress sync fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->andThrow(new RuntimeException('start Caddy ingress: podman exited with status 125'));
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Could not start Caddy ingress on the server. Check that Podman is running and port 80 is available.')
        ->assertJsonPath('detail', 'start Caddy ingress: podman exited with status 125');
});

it('syncs caddy ingress routes through flux when enabling ingress on an installed server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'nginx.example.com',
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'www.nginx.example.com',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://nginx.example.com {')
                && str_contains($apps[0]['config'], 'http://www.nginx.example.com {')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:8080'))
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy');

    expect($server->refresh()->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('running');
});

it('returns flux error details when server ingress activation fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'nginx.example.com',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->andThrow(new RuntimeException('validate Caddyfile: unrecognized directive'));
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Caddy rejected the generated ingress configuration. Check the domains and internal port, then try again.')
        ->assertJsonPath('detail', 'validate Caddyfile: unrecognized directive');
});

it('keeps editable v5 server builder capacity when disabling builder', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 5,
        'builder_cpu_quota' => '350%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => false,
            'builder_capacity' => 5,
            'builder_cpu_quota' => '350%',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 5);

    $server->refresh();

    expect($server->builder_enabled)->toBeFalse()
        ->and($server->builder_capacity)->toBe(5)
        ->and($server->builder_cpu_quota)->toBe('350%')
        ->and($server->capabilities)->toBe([]);
});

it('validates editable v5 server builder details', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => true,
            'builder_capacity' => 1001,
            'builder_cpu_quota' => str_repeat('a', 33),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity', 'builder_cpu_quota']);
});

it('requires positive editable v5 server builder capacity when builder is enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->id}/servers/{$server->id}", [
            'builder_enabled' => true,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});

it('validates v5 cluster creation input', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => '',
            'description' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'description']);
});

it('rejects duplicate v5 cluster names in the same team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('deletes an empty v5 cluster in the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Empty Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->id)
        ->assertNoContent();

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeFalse();
});

it('does not delete a v5 cluster that has servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => null,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->id)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only empty clusters can be deleted.');

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeTrue();
});

it('does not delete a v5 cluster outside the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherUser = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Other User',
        'email' => 'other-delete@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $cluster = Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'Other Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->id)
        ->assertNotFound();

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeTrue();
});

it('allows the same v5 cluster name in another team without leaking it', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherUser = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'Production Mesh',
        'description' => 'Other team cluster.',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => 'Current team cluster.',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.description', 'Current team cluster.');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/clusters')
        ->assertSuccessful()
        ->assertSee('Current team cluster.')
        ->assertDontSee('Other team cluster.');
});

it('shares existing projects and environments with the v5 dashboard page', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'gravy-truck', 'production');
    createV5ProjectWithEnvironment($team, 'rocket-bike', 'staging');
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($otherTeam, 'secret-saucer', 'private');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"projects":[', false)
        ->assertSee('"name":"gravy-truck"', false)
        ->assertSee('"uuid":"'.$project->uuid.'"', false)
        ->assertSee('"environments":[', false)
        ->assertSee('"name":"production"', false)
        ->assertSee('"uuid":"'.$environment->uuid.'"', false)
        ->assertSee('"selectedProjectUuid":"'.$project->uuid.'"', false)
        ->assertSee('"selectedEnvironmentUuid":"'.$environment->uuid.'"', false)
        ->assertDontSee('"id":"'.$project->id.'","uuid":"'.$project->uuid.'"', false)
        ->assertDontSee('"id":"'.$environment->id.'","uuid":"'.$environment->uuid.'"', false)
        ->assertDontSee($otherProject->name)
        ->assertDontSee($otherProject->uuid)
        ->assertDontSee($otherEnvironment->name)
        ->assertDontSee($otherEnvironment->uuid);
});

it('persists the selected v5 project and environment in the session', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    createV5ProjectWithEnvironment($team, 'alpha-project', 'production');
    [$selectedProject, $selectedEnvironment] = createV5ProjectWithEnvironment($team, 'zebra-project', 'staging');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/selection', [
            'project_uuid' => $selectedProject->uuid,
            'environment_uuid' => $selectedEnvironment->uuid,
        ])
        ->assertNoContent()
        ->assertSessionHas('v5.selectedProjectUuid', $selectedProject->uuid)
        ->assertSessionHas('v5.selectedEnvironmentUuid', $selectedEnvironment->uuid);

    $this
        ->actingAs($user)
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"selectedProjectUuid":"'.$selectedProject->uuid.'"', false)
        ->assertSee('"selectedEnvironmentUuid":"'.$selectedEnvironment->uuid.'"', false);
});

it('rejects persisted v5 selections outside the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($otherTeam, 'secret-saucer', 'private');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/selection', [
            'project_uuid' => $otherProject->uuid,
            'environment_uuid' => $otherEnvironment->uuid,
        ])
        ->assertForbidden()
        ->assertSessionMissing('v5.selectedProjectUuid')
        ->assertSessionMissing('v5.selectedEnvironmentUuid');
});

it('defines the v5 dashboard page as a shadcn styled canvas shell', function () {
    $dashboardPage = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $app = file_get_contents(resource_path('js/v5/app.tsx'));
    $navbarPath = resource_path('js/v5/components/app-navbar.tsx');

    expect(file_exists($navbarPath))->toBeTrue();

    $navbar = file_get_contents($navbarPath);
    $sheetPath = resource_path('js/v5/components/ui/sheet.tsx');

    expect(file_exists($sheetPath))->toBeTrue();

    expect($app)
        ->toContain('progress: {')
        ->toContain('delay: 10')
        ->toContain("color: '#fcd452'")
        ->toContain('showSpinner: false')
        ->not->toContain('TopNavigationLoadingIndicator')
        ->not->toContain('withApp:');

    expect($dashboardPage)
        ->toContain('Dashboard')
        ->toContain("import { AppNavbar } from '@/components/app-navbar';")
        ->not->toContain('function csrfToken()')
        ->toContain("import { csrfToken } from '@/lib/csrf';")
        ->toContain("import { Button } from '@/components/ui/button';")
        ->toContain("import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';")
        ->toContain("import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';")
        ->not->toContain("fetch('/v5/clusters'")
        ->toContain('<AppNavbar')
        ->toContain('bg-background text-foreground')
        ->toContain('h-dvh overflow-hidden bg-background text-foreground')
        ->toContain('relative h-full min-h-0 overflow-hidden pt-16')
        ->toContain('Add nginx')
        ->toContain('Select nginx server')
        ->toContain('selectedNginxServerId')
        ->toContain('server_id: selectedNginxServerId || null')
        ->toContain('Center')
        ->toContain('Delete')
        ->toContain('App configuration')
        ->toContain('openApplicationInspector')
        ->toContain('selectedInspectorApplication')
        ->toContain('onDoubleClick={(event) => openApplicationInspector(event, application)}')
        ->toContain('open={selectedInspectorApplication !== null}')
        ->toContain('<SheetContent side="right" className="w-full overflow-hidden bg-background sm:rounded-l-xl sm:border data-[side=right]:sm:!inset-y-4 data-[side=right]:sm:!h-auto data-[side=right]:sm:!w-[45vw] data-[side=right]:sm:!max-w-[45vw]"')
        ->toContain('showCloseButton blurOverlay={false}')
        ->toContain('<SheetHeader className="p-6 pb-4">')
        ->toContain('<div className="flex flex-1 flex-col gap-6 px-6 pb-6">')
        ->toContain('<Tabs defaultValue="overview"')
        ->toContain('<TabsList className="w-full justify-start" variant="line">')
        ->toContain('<TabsTrigger value="overview">Overview</TabsTrigger>')
        ->toContain('<TabsTrigger value="networking">Networking</TabsTrigger>')
        ->toContain('<TabsTrigger value="advanced">Advanced</TabsTrigger>')
        ->toContain('Double-click an application card to open configuration.')
        ->toContain("method: 'DELETE'")
        ->toContain('removeApplication')
        ->toContain('useEffect(() => {')
        ->toContain('setApplications(settledResources.applications);')
        ->toContain('centerOnCanvasNodes(settledResources.applications, settledResources.ingresses);')
        ->toContain('Caddy ingress')
        ->toContain('persistCaddyIngressPosition')
        ->toContain('startIngressDrag')
        ->toContain('fetch(`/v5/caddy-ingresses/${ingress.id}/position`')
        ->toContain("fetch('/v5/applications/nginx'")
        ->toContain('nginxServers = []')
        ->toContain('fetch(`/v5/applications/${application.id}/position`')
        ->not->toContain('<header')
        ->not->toContain('h-[calc(100dvh-4rem)]')
        ->not->toContain('flex h-dvh flex-col overflow-hidden bg-background text-foreground')
        ->toContain('No applications on this canvas yet.')
        ->not->toContain("import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';")
        ->not->toContain("fetch('/v5/selection'");

    expect(file_get_contents($sheetPath))
        ->toContain('blurOverlay = true')
        ->toContain('<SheetOverlay blur={blurOverlay} />');

    expect($navbar)
        ->toContain("import { Link, router, usePage } from '@inertiajs/react';")
        ->toContain("import { cn } from '@/lib/utils';")
        ->toContain("import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';")
        ->toContain("import { csrfToken } from '@/lib/csrf';")
        ->not->toContain('function csrfToken()')
        ->toContain('export function AppNavbar')
        ->toContain('/coolify-logo.svg')
        ->toContain('<Link')
        ->toContain('className="fixed inset-x-0 top-0 z-40 border-b border-border bg-background"')
        ->not->toContain('className="sticky top-0 z-40 shrink-0 border-b border-border bg-background"')
        ->toContain('hover:bg-muted')
        ->toContain('text-muted-foreground')
        ->toContain('SelectGroup')
        ->toContain('variant="ghost"')
        ->toContain('position="popper"')
        ->toContain('sideOffset={4}')
        ->toContain('Select a project')
        ->toContain('Select an environment')
        ->toContain('const { url } = usePage();')
        ->toContain("const isClustersPage = url.startsWith('/v5/clusters');")
        ->toContain('href="/v5"')
        ->toContain('href="/v5/clusters"')
        ->toContain('Clusters')
        ->toContain('className="relative flex h-16 items-center gap-3 px-4 sm:px-6"')
        ->toContain('className="absolute left-1/2 flex min-w-0 -translate-x-1/2 items-center justify-center gap-1 md:static md:flex-1 md:translate-x-0 md:justify-start md:gap-2"')
        ->toContain('className="max-w-[38vw] md:max-w-[10rem]"')
        ->toContain('className="max-w-[30vw] md:max-w-[10rem]"')
        ->toContain('aria-label="Open mobile menu"')
        ->toContain('<Sheet>')
        ->toContain('<SheetTrigger')
        ->toContain('<SheetContent side="right" className="w-72 max-w-[85vw] bg-background"')
        ->toContain('<SheetHeader>')
        ->toContain('<SheetTitle>Coolify</SheetTitle>')
        ->toContain('<SheetDescription className="sr-only">')
        ->toContain('<SheetClose')
        ->toContain('Move between Coolify v5 pages.')
        ->not->toContain('<SheetTitle className="sr-only">Navigation</SheetTitle>')
        ->toContain('className="hidden rounded-md px-3 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground md:inline-flex"')
        ->toContain('className="inline-flex rounded-md p-2 text-warning transition-colors hover:bg-muted hover:text-warning md:hidden"')
        ->toContain('Dashboard')
        ->not->toContain('Home')
        ->toContain("fetch('/v5/selection'")
        ->toContain('router.reload({')
        ->toContain("only: ['applications', 'selectedProjectUuid', 'selectedEnvironmentUuid']")
        ->toContain('void persistSelection(nextProjectUuid, nextEnvironmentUuid).then(refreshCurrentPageSelection);')
        ->toContain('void persistSelection(projectUuid, nextEnvironmentUuid).then(refreshCurrentPageSelection);')
        ->toContain("'X-CSRF-TOKEN': csrfToken()")
        ->not->toContain('isMobileMenuOpen')
        ->not->toContain('setIsMobileMenuOpen')
        ->not->toContain('isProjectEnvironmentMenuOpen')
        ->not->toContain('setIsProjectEnvironmentMenuOpen')
        ->not->toContain('Open project and environment selector')
        ->not->toContain('Close project and environment selector')
        ->not->toContain('DropdownMenu')
        ->not->toContain('fixed inset-0 bg-black/80')
        ->not->toContain('fixed inset-y-0 right-0')
        ->not->toContain('<a')
        ->not->toContain("import { Button } from '@/components/ui/button';")
        ->not->toContain('<Button')
        ->not->toContain("import { Separator } from '@/components/ui/separator';")
        ->not->toContain('<Separator')
        ->not->toContain('min-h-[calc(100vh-4rem)]')
        ->not->toContain('py-10')
        ->not->toContain('bg-background/95')
        ->not->toContain('backdrop-blur')
        ->not->toContain('supports-[backdrop-filter]')
        ->not->toContain('border-coolgray')
        ->not->toContain('className="w-[12rem]"')
        ->not->toContain('<h1>Coolify v5</h1>')
        ->not->toContain('<h2 id="clusters-heading">Clusters</h2>');

    expect(file_exists(resource_path('js/v5/components/top-navigation-loading-indicator.tsx')))->toBeFalse();
});

it('tracks v5 server actions with reusable per-id loading state', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));
    $pendingIdsHook = file_get_contents(resource_path('js/v5/lib/use-pending-ids.ts'));

    expect($clustersPage)
        ->toContain("import { usePendingIds } from '@/lib/use-pending-ids';")
        ->toContain('const checkingServers = usePendingIds<string>()')
        ->toContain('const isCheckingServer = checkingServers.has(server.id)')
        ->toContain('checkingServers.start(server.id)')
        ->toContain('checkingServers.finish(server.id)')
        ->not->toContain('const [checkingServerId, setCheckingServerId] = useState<string | null>(null)')
        ->not->toContain('setCheckingServerId(server.id)')
        ->not->toContain('setCheckingServerId(null)');

    expect($pendingIdsHook)
        ->toContain('export function usePendingIds')
        ->toContain('const [pendingIds, setPendingIds] = useState<Set<T>>(() => new Set())')
        ->toContain('start')
        ->toContain('finish')
        ->toContain('has')
        ->toContain('hasAny');
});

it('defines the v5 cluster management page and create cluster form', function () {
    $clustersPagePath = resource_path('js/v5/Pages/Clusters.tsx');
    $clustersPage = file_get_contents($clustersPagePath);
    $buttonComponent = file_get_contents(resource_path('js/v5/components/ui/button.tsx'));
    $dialogComponent = file_get_contents(resource_path('js/v5/components/ui/dialog.tsx'));
    $types = file_get_contents(resource_path('js/v5/types.ts'));

    expect(file_exists($clustersPagePath))->toBeTrue();

    preg_match('/<Button\s+type="button"\s+variant="coolify"\s+aria-label="Add server to cluster"/m', $clustersPage, $addServerButtonMatches);

    expect($addServerButtonMatches)->not->toBeEmpty();

    expect($clustersPage)
        ->toContain("import { Button } from '@/components/ui/button';")
        ->toContain("} from '@/components/ui/dialog';")
        ->toContain("} from '@/components/ui/dropdown-menu';")
        ->toContain("import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';")
        ->toContain("import { csrfToken } from '@/lib/csrf';")
        ->toContain("fetch('/v5/clusters'")
        ->toContain('aria-label="Select a cluster"')
        ->toContain('Cluster state')
        ->not->toContain('Last run')
        ->not->toContain('selectedCluster.lastCliStatus')
        ->not->toContain('selectedCluster.lastCliSummary')
        ->toContain('Generated mesh values are saved after bootstrap or extend runs.')
        ->not->toContain('CLI state')
        ->not->toContain('CLI-generated mesh values')
        ->toContain('Select a cluster')
        ->toContain('setSelectedClusterId(value)')
        ->toContain('aria-label="Create cluster"')
        ->toContain('setIsCreateDialogOpen(true)')
        ->toContain('Add cluster')
        ->toContain('variant="coolify"')
        ->not->toContain('border-warning bg-warning/10 text-foreground')
        ->not->toContain('border-primary bg-primary/10 text-foreground')
        ->toContain('<Dialog')
        ->toContain('<DialogTitle>Create cluster</DialogTitle>')
        ->toContain('<DialogDescription>')
        ->toContain('<DialogFooter>')
        ->not->toContain('<DialogClose')
        ->toContain('Cancel')
        ->toContain('<DialogContent className="max-w-md" showCloseButton={false}>')
        ->toContain('<DialogTitle>Confirm deletion</DialogTitle>')
        ->toContain('variant="delete"')
        ->toContain('aria-label="Add server to cluster"')
        ->toContain('setIsAddServerDialogOpen(true)')
        ->toContain('Add server')
        ->toContain('className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"')
        ->toContain('<DialogTitle>Add server</DialogTitle>')
        ->toContain('DialogContent className="max-w-3xl"')
        ->toContain('DialogContent className="max-w-2xl"')
        ->not->toContain('DialogContent className="max-h-[90dvh] max-w-3xl overflow-y-auto"')
        ->not->toContain('DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto"')
        ->toContain('fetch(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/bootstrap`')
        ->toContain('fetch(`/v5/clusters/${selectedCluster.id}`')
        ->toContain('hasBootstrapInProgress')
        ->toContain('Bootstrap')
        ->toContain('lastBootstrapOutput')
        ->toContain('Unable to queue bootstrap for this server.')
        ->toContain('border-destructive/30')
        ->toContain('fetch(`/v5/clusters/${selectedCluster.id}/servers`')
        ->toContain('fetch(`/v5/clusters/${selectedCluster.id}/servers/${editingServer.id}`')
        ->toContain('fetch(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/check`')
        ->toContain('fetch(`/v5/clusters/${cluster.id}/servers/${server.id}`')
        ->toContain("method: 'PATCH'")
        ->toContain("method: 'DELETE'")
        ->toContain('<DropdownMenu>')
        ->toContain('<DropdownMenuTrigger')
        ->toContain("import { DotsThreeIcon } from '@phosphor-icons/react';")
        ->toContain('variant="ghost"')
        ->toContain('size="icon-sm"')
        ->toContain('aria-label="Server actions"')
        ->toContain('<DotsThreeIcon data-icon="inline-start" weight="bold" />')
        ->not->toContain('>Server actions</DropdownMenuTrigger>')
        ->toContain('<DropdownMenuGroup>')
        ->toContain('<DropdownMenuSeparator />')
        ->toContain('Check connection')
        ->not->toContain('Check SSH')
        ->toContain('Not initialized')
        ->toContain('role="group"')
        ->toContain('aria-label="Server initialization"')
        ->toContain('rounded-l-md border border-r-0 border-destructive/30 bg-destructive/10')
        ->toContain('variant="coolify"')
        ->toContain('className="rounded-r-md"')
        ->toContain('onClick={() => void bootstrapServer(server)}')
        ->not->toContain('<DropdownMenuItem
                                                                                    disabled={isBootstrappingServer}')
        ->not->toContain('Bootstrap: {serverStatusLabel(server.status)}')
        ->not->toContain('Last bootstrap')
        ->not->toContain('{formatDate(server.lastBootstrappedAt)}')
        ->toContain('Latest SSH check: {latestSshCheck.status}')
        ->toContain('const notInitializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt === null) ?? []')
        ->toContain('const initializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt !== null) ?? []')
        ->toContain('Not initialized servers')
        ->toContain('{notInitializedServers.map(renderServerCard)}')
        ->toContain('{initializedServers.map(renderServerCard)}')
        ->toContain('{!isServerInitialized ? (')
        ->toContain('Show install logs')
        ->toContain('{canShowBootstrapLogs ? (')
        ->not->toContain('{!isServerInitialized && isBootstrapLogVisible ? (')
        ->toContain('Delete server')
        ->toContain('This removes it from this cluster inventory so you can add it again later.')
        ->not->toContain('<Button\n                                                                    type="button"\n                                                                    variant="outline"\n                                                                    size="sm"\n                                                                    disabled={isCheckingServer}')
        ->toContain('<DialogTitle>Edit server</DialogTitle>')
        ->toContain('Edit server')
        ->toContain('Save server')
        ->toContain('editServerBuilderCapacity')
        ->toContain('editServerBuilderCpuQuota')
        ->toContain('Networking and bootstrap settings stay locked after creation.')
        ->toContain('Bootstrap SSH user')
        ->toContain('Bootstrap SSH port')
        ->not->toContain('{server.sshUser}@{server.host}:{server.sshPort}')
        ->toContain('showAdvancedServerConfiguration')
        ->toContain('Node address override')
        ->toContain('Defaults to server IP')
        ->not->toContain('CLI node address')
        ->toContain('wireguardListenPortOverride')
        ->toContain('wireguardEndpointOverride')
        ->toContain('privateKeys')
        ->toContain('selectedPrivateKeyId')
        ->toContain('Private key')
        ->toContain('Select a private key')
        ->toContain('appearance-none')
        ->toContain('backgroundImage: `url("data:image/svg+xml')
        ->not->toContain('No private key')
        ->toContain('Create cluster')
        ->toContain('Cluster details')
        ->toContain('Servers in this cluster')
        ->toContain('selectedCluster')
        ->toContain('Server IP')
        ->toContain('{server.host}')
        ->not->toContain('<dt className="text-muted-foreground">CLI node</dt>')
        ->not->toContain('CLI node')
        ->not->toContain('{server.nodeAddress ?? server.host}')
        ->toContain('builderCapacity')
        ->toContain('{server.builderEnabled ? (')
        ->toContain('<dt className="text-muted-foreground">Builder CPU quota</dt>')
        ->toContain('server.builderCpuQuota')
        ->toContain(') : null}')
        ->toContain('privateKeyName')
        ->toContain('lastBootstrappedAt')
        ->toContain('This removes it from this cluster inventory so you can add it again later.')
        ->toContain('deleteCluster')
        ->toContain('fetch(`/v5/clusters/${cluster.id}`')
        ->toContain("method: 'DELETE'")
        ->toContain('Delete cluster')
        ->toContain('selectedCluster.serversCount === 0')
        ->not->toContain("selectedCluster.serversCount === 1 ? 'server' : 'servers'")
        ->toContain('Only empty clusters can be deleted.')
        ->toContain('min-h-dvh overflow-y-auto bg-background text-foreground lg:h-dvh lg:overflow-hidden')
        ->toContain('flex min-h-dvh overflow-visible px-4 pt-16 lg:h-full lg:min-h-0 lg:overflow-hidden lg:px-6')
        ->toContain('flex w-full flex-col gap-4 py-4 lg:min-h-0 lg:py-6')
        ->toContain('rounded-lg border border-border bg-card p-4')
        ->toContain('flex items-start justify-between gap-3')
        ->toContain('min-w-0 flex-1')
        ->toContain('flex shrink-0 items-center justify-end gap-2 sm:flex-wrap')
        ->toContain('aria-label="Select a cluster"')
        ->toContain('setSelectedClusterId(value)')
        ->not->toContain('flex max-h-80 flex-col rounded-lg border border-border bg-card lg:max-h-none lg:min-h-0')
        ->toContain('overflow-visible rounded-lg border border-border bg-card lg:min-h-0 lg:overflow-y-auto')
        ->not->toContain('flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end')
        ->toContain('mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2')
        ->not->toContain('lg:grid-cols-[20rem_minmax(0,1fr)]')
        ->not->toContain('lg:grid-cols-[20rem_minmax(0,1fr)_22rem]')
        ->not->toContain('New cluster')
        ->not->toContain('<aside className="rounded-lg border border-border bg-card p-5">')
        ->not->toContain('This is where the magic happens.');

    expect(substr_count($clustersPage, 'variant="coolify"'))->toBe(6)
        ->and($buttonComponent)
        ->toContain('coolify:')
        ->toContain('bg-coollabs-50')
        ->toContain('hover:bg-coollabs')
        ->toContain('delete:');

    expect($dialogComponent)
        ->toContain('@base-ui/react/dialog')
        ->toContain('z-50')
        ->toContain('max-h-[calc(100dvh-2rem)]')
        ->toContain('overflow-y-auto')
        ->toContain('top-1/2')
        ->toContain('-translate-y-1/2')
        ->not->toContain('top-4 bottom-4')
        ->not->toContain('top-1/2 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2')
        ->toContain('showCloseButton = true')
        ->toContain('aria-label="Close dialog"')
        ->toContain('<XIcon />')
        ->toContain('DialogTitle')
        ->toContain('DialogDescription')
        ->toContain("'mt-6 flex justify-end gap-2'");

    $v5Css = file_get_contents(resource_path('css/v5/app.css'));

    expect($v5Css)
        ->toContain('--color-warning: var(--warning);')
        ->toContain('--warning: #fcd452;')
        ->not->toContain('--ring: oklch(0.705 0.015 286.067);')
        ->not->toContain('--ring: oklch(0.552 0.016 285.938);');

    expect(substr_count($v5Css, '--ring: var(--warning);'))->toBe(2);

    expect($types)
        ->not->toContain('sshUser: string;')
        ->not->toContain('sshPort: number;')
        ->toContain('builderEnabled: boolean;')
        ->toContain('builderCapacity: number;')
        ->toContain('builderCpuQuota: string;')
        ->toContain('privateKeyName: string | null;')
        ->toContain('lastBootstrappedAt: string | null;')
        ->toContain('lastBootstrapStatus: string | null;')
        ->not->toContain('lastStatusOutput: string | null;');
});

it('uses the standard button size for the v5 delete cluster action', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->toContain("variant=\"delete\"\n                                                        size=\"default\"\n                                                        onClick={openDeleteClusterDialog}")
        ->not->toContain("variant=\"delete\"\n                                                        size=\"sm\"\n                                                        onClick={openDeleteClusterDialog}");
});

it('defines a ghost variant for compact v5 select triggers', function () {
    $select = file_get_contents(resource_path('js/v5/components/ui/select.tsx'));

    expect($select)
        ->toContain('@base-ui/react/select')
        ->not->toContain('radix-ui')
        ->toContain("variant = 'default'")
        ->toContain("variant === 'default'")
        ->toContain("variant === 'ghost'")
        ->toContain('border-transparent')
        ->toContain('h-auto')
        ->toContain('text-sm')
        ->toContain("position === 'popper' ? false : true");
});

it('provides reusable v5 form field primitives with reserved validation error space', function () {
    $fieldPath = resource_path('js/v5/components/ui/field.tsx');
    $inputPath = resource_path('js/v5/components/ui/input.tsx');
    $textareaPath = resource_path('js/v5/components/ui/textarea.tsx');
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect(file_exists($fieldPath))->toBeTrue()
        ->and(file_exists($inputPath))->toBeTrue()
        ->and(file_exists($textareaPath))->toBeTrue();

    $field = file_get_contents($fieldPath);
    $input = file_get_contents($inputPath);
    $textarea = file_get_contents($textareaPath);

    expect($field)
        ->toContain('function Field(')
        ->toContain('function FieldLabel(')
        ->toContain('function FieldError(')
        ->toContain('min-h-4')
        ->toContain('aria-live="polite"')
        ->toContain('message ? undefined : true')
        ->toContain('export { Field, FieldError, FieldLabel }');

    expect($input)
        ->toContain('function Input(')
        ->toContain('aria-invalid:border-destructive')
        ->toContain('export { Input };');

    expect($textarea)
        ->toContain('function Textarea(')
        ->toContain('aria-invalid:border-destructive')
        ->toContain('export { Textarea };');

    expect($clustersPage)
        ->toContain("import { Field, FieldError, FieldLabel } from '@/components/ui/field';")
        ->toContain("import { Input } from '@/components/ui/input';")
        ->toContain("import { Textarea } from '@/components/ui/textarea';")
        ->toContain('<FieldError message={errors.name?.[0]} />')
        ->toContain('<FieldError message={serverErrors.private_key_id?.[0]} />')
        ->not->toContain('{errors.name ? <span className="text-xs text-destructive">{errors.name[0]}</span> : null}')
        ->not->toContain('{serverErrors.name ? (');
});

it('uses the requested shadcn preset configuration for v5', function () {
    $components = json_decode(file_get_contents(base_path('components.json')), true);
    $css = file_get_contents(resource_path('css/v5/app.css'));

    expect($components['style'])
        ->toBe('base-lyra')
        ->and($components['tsx'])->toBeTrue()
        ->and($components['iconLibrary'])->toBe('phosphor')
        ->and($components['tailwind']['css'])->toBe('resources/css/v5/app.css')
        ->and($components['tailwind']['baseColor'])->toBe('zinc')
        ->and($css)->toContain('@import "@fontsource-variable/geist";')
        ->and($css)->toContain('--foreground: oklch(0.141 0.005 285.823);')
        ->and($css)->toContain('--background: #101010;')
        ->and($css)->toContain('button:not(:disabled)');
});

it('sizes the v5 app root with the dynamic mobile viewport', function () {
    $css = file_get_contents(resource_path('css/v5/app.css'));

    expect($css)
        ->toContain('min-height: 100dvh;')
        ->not->toContain('min-height: 100vh;');
});

it('selects a shared team when the session has no current team', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Auto Selected Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'admin']);

    $this
        ->actingAs($user)
        ->get('/v5')
        ->assertSuccessful()
        ->assertSessionHas('currentTeam')
        ->assertDontSee('Auto Selected Team')
        ->assertDontSee('admin');
});

it('serves v5 dev assets from the current request host', function (string $url, string $viteHost) {
    fakeFluxHealth();
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();

    $hotFile = public_path('hot');
    $originalHotFile = file_exists($hotFile) ? file_get_contents($hotFile) : null;

    file_put_contents($hotFile, 'http://configured-vite-host.test:5173');

    try {
        $response = $this
            ->actingAs($user)
            ->withSession(['currentTeam' => $team])
            ->get($url);

        $response
            ->assertSuccessful()
            ->assertSee("import RefreshRuntime from 'http://{$viteHost}:5173/@react-refresh'", false)
            ->assertSee("src=\"http://{$viteHost}:5173/@vite/client\"", false)
            ->assertSee("src=\"http://{$viteHost}:5173/resources/js/v5/app.tsx\"", false)
            ->assertDontSee('configured-vite-host.test', false);
    } finally {
        if ($originalHotFile === null) {
            @unlink($hotFile);
        } else {
            file_put_contents($hotFile, $originalHotFile);
        }
    }
})->with([
    'localhost' => ['http://localhost:8000/v5', 'localhost'],
    'tailscale ip' => ['http://100.64.0.10:8000/v5', '100.64.0.10'],
]);

it('configures the vite dev server for remote v5 access', function () {
    $viteConfig = file_get_contents(base_path('vite.config.js'));

    expect($viteConfig)
        ->toContain('host: "0.0.0.0"')
        ->toContain('allowedHosts: true')
        ->toContain('cors: true')
        ->toContain('const viteHmrHost = env.VITE_HMR_HOST || null;')
        ->toContain('hmr: viteHmrHost')
        ->not->toContain('hmr: viteHost');
});

it('shows when flux is unavailable', function () {
    $this->withoutVite();
    fakeFluxHealth(false, 'Flux socket was not found.');
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Katherine Johnson',
        'email' => 'katherine@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Flux Test Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('Unavailable')
        ->assertSee('Flux socket was not found.');
});

it('does not include coolify version controls on the v5 dashboard page', function () {
    $dashboardPage = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardPage)
        ->not->toContain('Check coolify version')
        ->not->toContain('/v5/coolify/version')
        ->not->toContain('Installed version:');
});

it('does not render flux status in the v5 navbar', function () {
    $navbar = file_get_contents(resource_path('js/v5/components/app-navbar.tsx'));

    expect($navbar)
        ->not->toContain('Flux: {flux?.label ??')
        ->not->toContain('title={flux?.socket ?? flux?.message ?? undefined}')
        ->not->toContain('{clusters.length} clusters')
        ->not->toContain('<h2 id="flux-status-heading">Flux status</h2>')
        ->not->toContain('<p>{flux.message}</p>')
        ->not->toContain('Socket: {flux.socket}');
});

it('syncs dev Lima VMs into v5 clusters and servers', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Dev Lima Key');

    $exitCode = Artisan::call('v5:sync-dev-lima-servers', [
        '--team-id' => $team->id,
        '--user-id' => $user->id,
        '--cluster' => 'Development-Lima',
        '--server' => [
            'coold-dev|host.docker.internal|developer|61332|100.64.0.1',
            'coold-dev-2|host.docker.internal|developer|61379|100.64.0.2',
        ],
    ]);

    expect($exitCode)->toBe(0)
        ->and(Cluster::query()->where('name', 'Development-Lima')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'host.docker.internal')->where('node_address', '100.64.0.1')->where('wireguard_management_ip', '100.64.0.1')->where('ssh_port', 61332)->where('private_key_id', $privateKey->id)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'host.docker.internal')->where('node_address', '100.64.0.2')->where('wireguard_management_ip', '100.64.0.2')->where('ssh_port', 61379)->where('private_key_id', $privateKey->id)->exists())->toBeTrue();
});

it('updates legacy dev Lima hostnames to Docker reachable SSH endpoints', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Dev Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
    ]);

    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $exitCode = Artisan::call('v5:sync-dev-lima-servers', [
        '--team-id' => $team->id,
        '--user-id' => $user->id,
        '--cluster' => 'Development-Lima',
        '--server' => [
            'coold-dev|host.docker.internal|developer|61332',
        ],
    ]);

    expect($exitCode)->toBe(0)
        ->and(V5Server::query()->where('name', 'coold-dev')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'host.docker.internal')->where('ssh_port', 61332)->exists())->toBeTrue()
        ->and(V5Server::query()->where('host', 'lima-coold-dev')->exists())->toBeFalse();
});

it('seeds dev Lima VMs into v5 clusters and servers idempotently', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    createV5PrivateKey($team, 'Dev Lima Key');

    (new V5DevLimaSeeder)->run();
    (new V5DevLimaSeeder)->run();

    $cluster = Cluster::query()->where('name', 'Development-Lima')->sole();

    expect($cluster->team_id)->toBe($team->id)
        ->and($cluster->created_by_user_id)->toBe($user->id)
        ->and($cluster->description)->toBe('Local Lima development cluster managed by scripts/dev.sh.')
        ->and(V5Server::query()->count())->toBe(2)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'coold-dev.local')->where('ssh_user', 'coolify')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'coold-dev-2.local')->where('ssh_user', 'coolify')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev')->where('node_address', '100.64.0.1')->where('wireguard_management_ip', '100.64.0.1')->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('node_address', '100.64.0.2')->where('wireguard_management_ip', '100.64.0.2')->exists())->toBeTrue()
        ->and(V5Server::query()->where('status', 'installed')->count())->toBe(2)
        ->and(V5Server::query()->where('builder_enabled', false)->where('builder_capacity', 0)->count())->toBe(2)
        ->and(V5Server::query()->where('cluster_id', $cluster->id)->count())->toBe(2);
});

it('seeds dev Lima VMs by updating existing named servers', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    createV5PrivateKey($team, 'Dev Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
    ]);

    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'coold-dev',
        'host' => 'old-host.local',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now()->subDay(),
    ]);

    (new V5DevLimaSeeder)->run();

    expect(V5Server::query()->where('name', 'coold-dev')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'coold-dev.local')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->count())->toBe(2);
});

function fakeFluxHealth(bool $available = true, string $message = 'Flux is running.'): void
{
    app()->instance(FluxHealth::class, Mockery::mock(FluxHealth::class, function (MockInterface $mock) use ($available, $message) {
        $mock->shouldReceive('check')
            ->once()
            ->andReturn([
                'available' => $available,
                'label' => $available ? 'Running' : 'Unavailable',
                'message' => $message,
                'socket' => '/run/coolify/flux.sock',
            ]);
    }));
}

function createSharedUserAndTeamTables(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->default('Anonymous');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('description')->nullable();
        $table->boolean('personal_team')->default(false);
        $table->boolean('show_boarding')->default(false);
        $table->timestamps();
    });

    Schema::create('private_keys', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('name');
        $table->string('description')->nullable();
        $table->longText('private_key');
        $table->string('fingerprint')->nullable();
        $table->boolean('is_git_related')->default(false);
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->string('name');
        $table->text('description')->nullable();
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('environments', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('project_id');
        $table->timestamps();
        $table->text('description')->nullable();
        $table->string('uuid');
    });

    Schema::create('v5_clusters', function ($table) {
        $table->id();
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

    Schema::create('v5_servers', function ($table) {
        $table->id();
        $table->string('uuid')->nullable()->unique();
        $table->foreignId('team_id');
        $table->foreignId('cluster_id')->nullable();
        $table->foreignId('created_by_user_id');
        $table->foreignId('private_key_id')->nullable();
        $table->string('name');
        $table->string('host');
        $table->string('ssh_user');
        $table->unsignedInteger('ssh_port');
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
    });

    Schema::create('v5_container_statuses', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('server_id');
        $table->string('container_id');
        $table->string('container_name')->nullable();
        $table->string('image')->nullable();
        $table->string('status')->default('unknown');
        $table->text('status_message')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();

        $table->unique(['server_id', 'container_id']);
    });

    Schema::create('v5_applications', function ($table) {
        $table->id();
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
        $table->string('runtime_container_id')->nullable();
        $table->string('mesh_namespace')->default('default');
        $table->boolean('ingress_enabled')->default(false);
        $table->unsignedSmallInteger('internal_port')->nullable();
        $table->integer('canvas_x')->default(0);
        $table->integer('canvas_y')->default(0);
        $table->timestamps();
    });

    Schema::create('v5_application_domains', function ($table) {
        $table->id();
        $table->foreignId('application_id');
        $table->string('domain');
        $table->timestamps();

        $table->unique(['application_id', 'domain']);
    });

    Schema::create('v5_resource_connections', function ($table) {
        $table->id();
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

    Schema::create('team_user', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('user_id');
        $table->string('role')->default('member');
        $table->timestamps();

        $table->unique(['team_id', 'user_id']);
    });
}

/**
 * @return array{0: Project, 1: Environment}
 */
/**
 * @param  array<int, string>  $command
 */
function cliFlagValue(array $command, string $flag): ?string
{
    $index = array_search($flag, $command, true);

    if ($index === false || ! isset($command[$index + 1])) {
        return null;
    }

    return $command[$index + 1];
}

function createV5ProjectWithEnvironment(Team $team, string $projectName, string $environmentName): array
{
    $project = Project::withoutEvents(fn () => Project::query()->forceCreate([
        'uuid' => str($projectName)->slug().'-uuid',
        'name' => $projectName,
        'description' => null,
        'team_id' => $team->id,
    ]));

    $environment = Environment::withoutEvents(fn () => Environment::query()->forceCreate([
        'uuid' => str($environmentName)->slug().'-uuid',
        'name' => $environmentName,
        'description' => null,
        'project_id' => $project->id,
    ]));

    return [$project, $environment];
}

function createV5PrivateKey(Team $team, string $name): PrivateKey
{
    return PrivateKey::withoutEvents(fn () => PrivateKey::query()->forceCreate([
        'uuid' => str($name)->slug().'-uuid',
        'name' => $name,
        'description' => null,
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
        'fingerprint' => str($name)->slug()->toString(),
        'is_git_related' => false,
        'team_id' => $team->id,
    ]));
}

/**
 * @return array{0: User, 1: Team}
 */
function createV5UserWithTeam(string $email = 'margaret@example.com'): array
{
    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => $email,
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Tooling Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    return [$user, $team];
}

it('configures v5 dev lima host resolver for coolify internal dns', function () {
    $script = file_get_contents(base_path('scripts/coold-vm.sh'));

    expect($script)
        ->toContain('configure_system_resolved')
        ->toContain('ensure_mesh_dns_anchor')
        ->toContain('coolify-v5-mesh-dns-anchor')
        ->toContain('resolvectl dns podman1 "$CONTAINER_GATEWAY"')
        ->toContain("resolvectl domain podman1 '~coolify.internal'")
        ->toContain('resolvectl default-route podman1 false');
});
