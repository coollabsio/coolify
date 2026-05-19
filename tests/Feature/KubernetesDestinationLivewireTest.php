<?php

use App\Livewire\Destination\New\Kubernetes as NewKubernetesDestination;
use App\Livewire\Destination\Show as ShowDestination;
use App\Models\InstanceSettings;
use App\Models\KubernetesCluster;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'is_build_server' => false,
        'force_disabled' => false,
    ]);
});

test('creates kubernetes destination from livewire form', function () {
    Livewire::test(NewKubernetesDestination::class)
        ->set('serverId', (string) $this->server->id)
        ->set('name', 'production-kubernetes')
        ->set('namespace', 'production')
        ->set('createNamespace', true)
        ->set('kubeconfig', "apiVersion: v1\nkind: Config\n")
        ->set('ingressClass', 'nginx')
        ->set('serviceType', 'LoadBalancer')
        ->set('replicas', 2)
        ->set('autoscalingEnabled', true)
        ->set('minReplicas', 2)
        ->set('maxReplicas', 5)
        ->set('targetCpuUtilizationPercentage', 60)
        ->set('nodeSelector', 'workload=apps')
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('kubernetes_clusters', [
        'server_id' => $this->server->id,
        'name' => 'production-kubernetes',
        'namespace' => 'production',
        'service_type' => 'LoadBalancer',
        'replicas' => 2,
        'autoscaling_enabled' => true,
    ]);
});

test('updates kubernetes destination from show page', function () {
    $destination = KubernetesCluster::factory()->create([
        'server_id' => $this->server->id,
        'namespace' => 'default',
        'kubeconfig' => "apiVersion: v1\nkind: Config\n",
    ]);

    Livewire::test(ShowDestination::class, ['destination_uuid' => $destination->uuid])
        ->assertSee('Kubeconfig')
        ->set('namespace', 'apps')
        ->set('replicas', 3)
        ->set('autoscalingEnabled', true)
        ->set('minReplicas', 2)
        ->set('maxReplicas', 6)
        ->set('podDisruptionBudgetEnabled', true)
        ->set('podDisruptionBudgetMinAvailable', '50%')
        ->call('submit')
        ->assertHasNoErrors();

    expect($destination->fresh())
        ->namespace->toBe('apps')
        ->replicas->toBe(3)
        ->autoscaling_enabled->toBeTrue()
        ->pod_disruption_budget_enabled->toBeTrue()
        ->pod_disruption_budget_min_available->toBe('50%');
});

test('validates kubernetes destination replica ranges', function () {
    Livewire::test(NewKubernetesDestination::class)
        ->set('serverId', (string) $this->server->id)
        ->set('name', 'bad-kubernetes')
        ->set('namespace', 'default')
        ->set('kubeconfigPath', '/etc/kubernetes/config')
        ->set('autoscalingEnabled', true)
        ->set('minReplicas', 5)
        ->set('maxReplicas', 2)
        ->call('submit');

    $this->assertDatabaseMissing('kubernetes_clusters', [
        'name' => 'bad-kubernetes',
    ]);
});
