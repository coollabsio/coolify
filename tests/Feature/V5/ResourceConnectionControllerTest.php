<?php

use App\Models\V5\Application as V5Application;
use App\Models\V5\Cluster;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('uses v5 resource uuids for resource connection requests and responses', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.10',
    ]);
    $source = V5Application::query()->create([
        'uuid' => 'source-application-uuid',
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'source',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'source-container',
        'status' => 'running',
    ]);
    $target = V5Application::query()->create([
        'uuid' => 'target-application-uuid',
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'target',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'target-container',
        'status' => 'running',
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/resource-connections', [
            'resource_one' => ['type' => 'application', 'uuid' => $source->uuid],
            'resource_two' => ['type' => 'application', 'uuid' => $target->uuid],
        ])
        ->assertCreated()
        ->assertJsonPath('connection.fromApplicationId', $source->uuid)
        ->assertJsonPath('connection.toApplicationId', $target->uuid);

    $connectionUuid = $response->json('connection.id');

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('applyFirewallRule')->once()->andReturn('Firewall rule applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/resource-connections/{$connectionUuid}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [8080],
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath("connection.portsByDirection.{$source->uuid}->{$target->uuid}.0", '8080');

    expect(ResourceConnection::query()->first())
        ->resource_one_id->toBe($source->id)
        ->resource_two_id->toBe($target->id);
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
        'wireguard_management_ip' => '100.64.0.10',
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
            'resource_one' => ['type' => 'application', 'uuid' => $source->uuid],
            'resource_two' => ['type' => 'application', 'uuid' => $target->uuid],
        ])
        ->assertCreated()
        ->assertJsonPath('connection.applicationIds.0', $source->uuid)
        ->assertJsonPath('connection.applicationIds.1', $target->uuid);

    $connectionId = $response->json('connection.id');

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('applyFirewallRule')->twice()->andReturn('Firewall rule applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connectionId}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [80],
                "{$target->uuid}->{$source->uuid}" => [443],
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath("connection.portsByDirection.{$source->uuid}->{$target->uuid}.0", '80')
        ->assertJsonPath("connection.portsByDirection.{$target->uuid}->{$source->uuid}.0", '443');

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
        ->assertSee("\"{$source->uuid}->{$target->uuid}\":[\"80\"]", false)
        ->assertSee("\"{$target->uuid}->{$source->uuid}\":[\"443\"]", false);
});

it('reports flux failures when syncing v5 resource connection firewall rules', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    createSharedUserAndTeamTables();
    Exceptions::fake();

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

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->andThrow(new RuntimeException('resolve firewall endpoint coolify-v5-api on coolify-default-mesh'));
    // The compensation pass rolls the node back by revoking the attempted rule.
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->with(Mockery::type('string'), "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:5432")
        ->andReturn('Firewall rule removed.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->uuid}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [5432],
            ],
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Could not sync firewall rules through Flux.')
        ->assertJsonPath('detail', 'The previous rules were restored. Check the server diagnostics and try again.');

    expect($connection->rules()->count())->toBe(0);

    // The raw Flux/coold error is never leaked to the client, only reported server-side.
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'resolve firewall endpoint coolify-v5-api on coolify-default-mesh');
});

it('syncs cross-server v5 resource connection ports on both endpoint hosts', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
    ]);
    $sourceServer = V5Server::query()->create([
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
    $targetServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.11',
        'capabilities' => [],
    ]);
    $source = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $sourceServer->id,
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
        'server_id' => $targetServer->id,
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
    $expectedRule = [
        'id' => $firewallRuleId,
        'namespace' => 'default',
        'src' => 'coolify-v5-api',
        'dst' => 'coolify-v5-postgres',
        'proto' => 'tcp',
        'port' => 5432,
    ];

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $expectedRule)
        ->andReturn('Firewall rule applied on source host.');
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $expectedRule)
        ->andReturn('Firewall rule applied on target host.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->uuid}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [5432],
            ],
        ])
        ->assertSuccessful();
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
        ->with(Mockery::type('string'), [
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
        ->with(Mockery::type('string'), $firewallRuleId)
        ->andReturn('Firewall rule removed.');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->uuid}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [5432],
            ],
        ])
        ->assertSuccessful();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->deleteJson("/v5/resource-connections/{$connection->uuid}")
        ->assertNoContent();
});

it('rejects firewall sync when a connected application has no server host id', function () {
    createSharedUserAndTeamTables();
    Exceptions::fake();

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
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
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

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->uuid}", [
            'ports_by_direction' => [
                "{$source->uuid}->{$target->uuid}" => [5432],
            ],
        ])
        ->assertStatus(502)
        ->assertJsonPath('detail', 'The previous rules were restored. Check the server diagnostics and try again.');

    expect($connection->rules()->count())->toBe(0);
});

it('still deletes a v5 resource connection whose endpoint lost its server host id', function () {
    createSharedUserAndTeamTables();
    Exceptions::fake();

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
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
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
    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->deleteJson("/v5/resource-connections/{$connection->uuid}")
        ->assertNoContent();

    expect(ResourceConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});
