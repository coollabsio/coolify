<?php

use App\Events\V5CanvasResourceUpdated;
use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Jobs\V5DeployApplicationJob;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Cluster;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('uses v5 resource uuids at http boundaries while keeping database ids internal', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
    $server = V5Server::query()->create([
        'uuid' => 'server-public-uuid',
        'team_id' => $team->id,
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
        'uuid' => 'application-public-uuid',
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

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->uuid}/position", [
            'canvas_x' => 123,
            'canvas_y' => 456,
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.id', $application->uuid);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->id}/position", [
            'canvas_x' => 789,
            'canvas_y' => 999,
        ])
        ->assertNotFound();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-id');
    $fluxClient->shouldReceive('startContainer')->once()->andReturn('Container started.');
    $fluxClient->shouldReceive('inspectContainer')->once()->andReturn(['State' => ['Running' => true]]);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'server_uuid' => $server->uuid,
            'image' => 'docker.io/library/nginx:alpine',
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.id', fn (string $id): bool => $id !== (string) V5Application::query()->latest('id')->value('id'));
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
            Mockery::type('string'),
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://app.example.com {')
                && ! str_contains($apps[0]['config'], 'https://')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:3000'))
        )
        ->andReturn('Caddy ingress applied.');
    expectCaddyIngressFirewallRule($fluxClient);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertSuccessful();
});

it('rejects malformed ingress domains before they reach the caddy configuration', function (string $domain) {
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
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => [$domain],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domains.0');

    expect($application->refresh()->ingress_enabled)->toBeFalse()
        ->and($application->domains()->count())->toBe(0);
})->with([
    'caddy block injection' => "evil.com {\n} http://x",
    'embedded newline' => "evil\n.com",
    'braces' => 'evil.com{}',
    'whitespace' => 'evil.com respond',
    'too long' => str_repeat('a', 254),
]);

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

    fakeSuccessfulNginxFluxDeployment();

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertAccepted()
        ->assertJsonPath('application.name', 'nginx-test')
        ->assertJsonPath('application.image', 'docker.io/library/nginx:alpine')
        ->assertJsonPath('application.status', 'creating')
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

it('creates an nginx v5 application with a custom image', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_uuid' => $privateKey->uuid,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    fakeSuccessfulNginxFluxDeployment(image: 'docker.io/library/httpd:alpine');

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'image' => 'docker.io/library/httpd:alpine',
        ])
        ->assertAccepted()
        ->assertJsonPath('application.image', 'docker.io/library/httpd:alpine');

    expect(V5Application::query()
        ->where('image', 'docker.io/library/httpd:alpine')
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

    fakeSuccessfulNginxFluxDeployment();

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'server_uuid' => $selectedServer->uuid,
        ])
        ->assertAccepted()
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

    fakeSuccessfulNginxFluxDeployment();

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertAccepted()
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
        'private_key_uuid' => $privateKey->uuid,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('pullImage')->once()->andThrow(new RuntimeException('podman failed'));
    });

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertAccepted()
        ->assertJsonPath('application.status', 'creating');

    expect(V5Application::query()
        ->where('status', 'failed')
        ->where('status_message', 'podman failed')
        ->exists())->toBeTrue();
});

it('queues the nginx deploy instead of running it in the http request', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'last_bootstrapped_at' => now(),
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx')
        ->assertAccepted()
        ->assertJsonPath('application.status', 'creating');

    Queue::assertPushed(V5DeployApplicationJob::class);
});

it('refuses to deploy nginx to a server that is not bootstrapped', function () {
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
        'status' => 'added',
        'capabilities' => [],
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->postJson('/v5/applications/nginx', [
            'server_uuid' => $server->uuid,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Bootstrap server edge-01 before deploying to it.');

    expect(V5Application::query()->count())->toBe(0);
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
        'private_key_uuid' => $privateKey->uuid,
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
        ->deleteJson("/v5/applications/{$application->uuid}")
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
        ->deleteJson("/v5/applications/{$application->uuid}")
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

it('cleans v5 application ingress routes and firewall connections before deleting an application', function () {
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
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.10',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'api.example.com',
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
        'mesh_namespace' => 'default',
    ]);
    $connection = ResourceConnection::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'resource_one_type' => $application->getMorphClass(),
        'resource_one_id' => $application->id,
        'resource_two_type' => $target->getMorphClass(),
        'resource_two_id' => $target->id,
        'resource_pair_key' => "application:{$application->id}|application:{$target->id}",
        'created_by_user_id' => $user->id,
    ]);
    $connection->rules()->create([
        'source_resource_type' => $application->getMorphClass(),
        'source_resource_id' => $application->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $firewallRuleId = "v5-resource-connection:{$connection->id}:{$application->id}:{$target->id}:tcp:5432";
    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $firewallRuleId)
        ->andReturn('Firewall rule removed.');
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            Mockery::type('string'),
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => $apps === [])
        )
        ->andReturn('Caddy ingress applied.');
    expectCaddyIngressFirewallRule($fluxClient);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->uuid}")
        ->assertNoContent();

    expect(V5Application::query()->whereKey($application->id)->exists())->toBeFalse()
        ->and(ResourceConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('deletes a v5 application locally when remote cleanup is explicitly skipped', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'edge-01',
        'host' => 'coold-dev-2.local',
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
        'status' => 'unknown',
        'runtime_container_id' => 'nginx-container-id',
    ]);

    Process::fake([
        '*' => Process::result(errorOutput: 'ssh: Could not resolve hostname coold-dev-2.local: Try again', exitCode: 255),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->uuid}", ['delete_locally' => true])
        ->assertNoContent();

    Process::assertNothingRan();

    expect(V5Application::query()->whereKey($application->id)->exists())->toBeFalse();
});

it('restores v5 application ingress and firewall rules when application runtime deletion fails', function () {
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
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.10',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'api.example.com',
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
        'mesh_namespace' => 'default',
    ]);
    $connection = ResourceConnection::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'resource_one_type' => $application->getMorphClass(),
        'resource_one_id' => $application->id,
        'resource_two_type' => $target->getMorphClass(),
        'resource_two_id' => $target->id,
        'resource_pair_key' => "application:{$application->id}|application:{$target->id}",
        'created_by_user_id' => $user->id,
    ]);
    $connection->rules()->create([
        'source_resource_type' => $application->getMorphClass(),
        'source_resource_id' => $application->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $firewallRuleId = "v5-resource-connection:{$connection->id}:{$application->id}:{$target->id}:tcp:5432";
    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $firewallRuleId)
        ->andReturn('Firewall rule removed.');
    $fluxClient
        ->shouldReceive('applyIngress')
        ->twice()
        ->with(
            Mockery::type('string'),
            'caddy',
            Mockery::type('string'),
            Mockery::on(fn (array $apps): bool => $apps === [] || collect($apps)->contains(fn (array $app): bool => $app['name'] === "app_{$application->id}"))
        )
        ->andReturn('Caddy ingress applied.');
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->twice()
        ->with(Mockery::type('string'), Mockery::on(fn (array $rule): bool => $rule['id'] === 'v5-caddy-ingress:80'))
        ->andReturn('Caddy firewall rule applied.');
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with(Mockery::type('string'), Mockery::on(fn (array $rule): bool => $rule['id'] === $firewallRuleId && $rule['port'] === 5432))
        ->andReturn('Firewall rule restored.');
    app()->instance(FluxClient::class, $fluxClient);
    Process::fake([
        '*' => Process::result(errorOutput: "ssh failed\n", exitCode: 1),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/applications/{$application->uuid}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'ssh failed')
        ->assertJsonPath('can_delete_locally', true);

    $application->refresh();

    expect(V5Application::query()->whereKey($application->id)->exists())->toBeTrue()
        ->and($application->ingress_enabled)->toBeTrue()
        ->and($application->internal_port)->toBe(8080)
        ->and($application->domains()->pluck('domain')->all())->toBe(['api.example.com'])
        ->and($connection->rules()->count())->toBe(1);
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
        ->deleteJson("/v5/applications/{$application->uuid}")
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
        ->patchJson("/v5/applications/{$application->uuid}/position", [
            'canvas_x' => 320,
            'canvas_y' => -160,
        ])
        ->assertSuccessful()
        ->assertJsonPath('application.canvasX', 320)
        ->assertJsonPath('application.canvasY', -160);

    expect($application->refresh()->canvas_x)->toBe(320)
        ->and($application->canvas_y)->toBe(-160);
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
            ->with(Mockery::type('string'))
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
        ->assertJsonPath('applications.0.id', $application->uuid)
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
            ->with(Mockery::type('string'))
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
        ->assertJsonPath('caddyIngresses.0.id', $server->uuid)
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
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
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
            Mockery::type('string'),
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            []
        )
        ->andReturn('Caddy ingress applied.');
    expectCaddyIngressFirewallRule($fluxClient);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
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
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
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
            Mockery::type('string'),
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://app.example.com {')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:3000'))
        )
        ->andReturn('Caddy ingress applied.');
    expectCaddyIngressFirewallRule($fluxClient);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
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
        ->patchJson("/v5/applications/{$application->uuid}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 3000,
            'domains' => ['app.example.com'],
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Could not start Caddy ingress on the server. Check that Podman is running and port 80 is available.')
        ->assertJsonPath('detail', 'start Caddy ingress: podman exited with status 125');

    expect($application->refresh()->ingress_enabled)->toBeFalse()
        ->and($application->internal_port)->toBeNull()
        ->and($application->domains()->count())->toBe(0);
});

it('rejects a domain already claimed by another application on the same ingress server', function () {
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
        'status' => 'added',
        'capabilities' => ['ingress'],
        'ingress_type' => 'caddy',
    ]);
    $otherServer = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'capabilities' => ['ingress'],
        'ingress_type' => 'caddy',
    ]);
    $owner = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'owner-app',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-owner',
        'status' => 'running',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $owner->id,
        'domain' => 'app.example.com',
    ]);
    $sameServerApp = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'challenger-app',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-challenger',
        'status' => 'running',
    ]);
    $otherServerApp = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $otherServer->id,
        'created_by_user_id' => $user->id,
        'name' => 'elsewhere-app',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-elsewhere',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/applications/{$sameServerApp->uuid}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 8081,
            'domains' => ['App.Example.Com'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The domain app.example.com is already used by application "owner-app" on this server.');

    expect($sameServerApp->refresh()->ingress_enabled)->toBeFalse();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/applications/{$otherServerApp->uuid}/ingress", [
            'ingress_enabled' => true,
            'internal_port' => 8081,
            'domains' => ['app.example.com'],
        ])
        ->assertSuccessful();

    expect($otherServerApp->refresh()->ingress_enabled)->toBeTrue();
});

it('does not mark creating v5 applications as exited during a refresh', function () {
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
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'creating',
        'status_message' => 'Starting nginx container.',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->andReturn([]);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
            '_token' => 'test-csrf-token',
        ])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->postJson('/v5/applications/refresh')
        ->assertSuccessful();

    expect($application->refresh()->status)->toBe('creating');
});

it('returns deploy status without logs for a failed v5 application that has no container', function () {
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
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'failed',
        'status_message' => 'host not connected',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldNotReceive('containerLogs');
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/applications/{$application->uuid}/logs")
        ->assertSuccessful()
        ->assertJson([
            'status' => 'failed',
            'statusMessage' => 'host not connected',
            'containerId' => null,
            'logs' => null,
            'logsError' => null,
        ]);
});

it('returns container logs for a v5 application through flux', function () {
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
        'node_address' => '203.0.113.10',
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
        'status_message' => 'Container running.',
        'runtime_container_id' => 'container-abc',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('containerLogs')
            ->once()
            ->with(Mockery::type('string'), 'container-abc')
            ->andReturn("nginx started\nlistening on :80");
    });

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/applications/{$application->uuid}/logs")
        ->assertSuccessful()
        ->assertJson([
            'status' => 'running',
            'containerId' => 'container-abc',
            'logs' => "nginx started\nlistening on :80",
            'logsError' => null,
        ]);
});

it('reports a friendly error when the node coold does not support container logs', function () {
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
        'status_message' => 'Container running.',
        'runtime_container_id' => 'container-abc',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('containerLogs')
            ->once()
            ->andThrow(new UnsupportedCooldVerb('containers.logs', 'primitive containers.logs is not supported by host'));
    });

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/applications/{$application->uuid}/logs")
        ->assertSuccessful()
        ->assertJson([
            'logs' => null,
            'logsError' => "This node's coold does not support container logs.",
        ]);
});

it('hides another team v5 application logs as a not found', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$otherUser, $otherTeam] = createV5UserWithTeam('grace@example.com');
    [$project, $environment] = createV5ProjectWithEnvironment($otherTeam, 'Other Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'edge-99',
        'host' => '203.0.113.99',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.99',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $otherTeam->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-9',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/applications/{$application->uuid}/logs")
        ->assertNotFound();
});
