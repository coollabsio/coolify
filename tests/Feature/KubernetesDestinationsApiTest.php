<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\KubernetesCluster;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['app.maintenance.store' => 'array']);
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['read', 'write'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

function kubernetesDestinationHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->bearerToken,
        'Content-Type' => 'application/json',
    ];
}

test('creates and reads kubernetes destinations through the api', function () {
    $response = $this->withHeaders(kubernetesDestinationHeaders())
        ->postJson('/api/v1/destinations/kubernetes', [
            'name' => 'Production Kubernetes',
            'server_uuid' => $this->server->uuid,
            'namespace' => 'production',
            'kubeconfig' => "apiVersion: v1\nkind: Config\n",
            'ingress_class' => 'nginx',
            'service_type' => 'ClusterIP',
            'replicas' => 2,
            'autoscaling_enabled' => true,
            'min_replicas' => 2,
            'max_replicas' => 5,
            'target_cpu_utilization_percentage' => 60,
        ]);

    $response->assertCreated()
        ->assertJsonPath('type', 'kubernetes')
        ->assertJsonPath('name', 'Production Kubernetes')
        ->assertJsonPath('server_uuid', $this->server->uuid)
        ->assertJsonMissingPath('kubeconfig');

    $uuid = $response->json('uuid');
    $this->assertDatabaseHas('kubernetes_clusters', [
        'uuid' => $uuid,
        'server_id' => $this->server->id,
        'namespace' => 'production',
        'replicas' => 2,
    ]);

    $this->withHeaders(kubernetesDestinationHeaders())
        ->getJson('/api/v1/destinations')
        ->assertOk()
        ->assertJsonFragment(['uuid' => $uuid, 'type' => 'kubernetes']);

    $this->withHeaders(kubernetesDestinationHeaders())
        ->getJson("/api/v1/destinations/{$uuid}")
        ->assertOk()
        ->assertJsonPath('namespace', 'production')
        ->assertJsonMissingPath('kubeconfig');
});

test('updates kubernetes destination settings through the api', function () {
    $destination = KubernetesCluster::factory()->create([
        'server_id' => $this->server->id,
        'namespace' => 'default',
        'replicas' => 1,
    ]);

    $this->withHeaders(kubernetesDestinationHeaders())
        ->patchJson("/api/v1/destinations/kubernetes/{$destination->uuid}", [
            'namespace' => 'apps',
            'replicas' => 3,
            'min_replicas' => 2,
            'max_replicas' => 6,
            'node_selector' => 'workload=apps',
        ])
        ->assertOk()
        ->assertJsonPath('namespace', 'apps')
        ->assertJsonPath('replicas', 3);

    expect($destination->fresh())
        ->namespace->toBe('apps')
        ->replicas->toBe(3)
        ->node_selector->toBe('workload=apps');
});

test('blocks cross team server and destination access', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = KubernetesCluster::factory()->create(['server_id' => $otherServer->id]);

    $this->withHeaders(kubernetesDestinationHeaders())
        ->postJson('/api/v1/destinations/kubernetes', [
            'name' => 'Wrong Team',
            'server_uuid' => $otherServer->uuid,
            'namespace' => 'default',
        ])
        ->assertNotFound();

    $this->withHeaders(kubernetesDestinationHeaders())
        ->getJson("/api/v1/destinations/{$otherDestination->uuid}")
        ->assertNotFound();
});

test('does not delete kubernetes destinations with attached resources', function () {
    $destination = KubernetesCluster::factory()->create(['server_id' => $this->server->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->withHeaders(kubernetesDestinationHeaders())
        ->deleteJson("/api/v1/destinations/kubernetes/{$destination->uuid}")
        ->assertBadRequest();
});
