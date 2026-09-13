<?php

use App\Actions\Node\FetchNodeDiscoveryEndpoints;
use App\Livewire\Node\InternalDns;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'name' => 'DNS Node',
        'ip' => '192.0.2.10',
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->endpoints = [[
        'hostname' => 'web.default.coolify.internal',
        'workload' => 'web',
        'namespace' => 'default',
        'owner_node_ip' => '10.240.0.2',
        'container_ip' => '10.240.0.2',
        'state' => 'running',
        'health' => 'healthy',
        'updated_at' => 1_789_319_482,
        'expires_at' => 1_789_319_782,
    ]];

    FetchNodeDiscoveryEndpoints::shouldRun()->andReturn($this->endpoints);
});

it('parses valid Corrosion discovery rows and ignores malformed rows', function () {
    $rows = FetchNodeDiscoveryEndpoints::parse(implode("\n", [
        'web|default|10.240.0.2|10.240.0.2|running|healthy|1789319482|1789319782',
        'malformed',
        'bad name|default|10.240.0.2|10.240.0.2|running|healthy|1789319482|1789319782',
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['hostname'])->toBe('web.default.coolify.internal')
        ->and($rows[0]['container_ip'])->toBe('10.240.0.2')
        ->and($rows[0]['expires_at'])->toBe(1_789_319_782);
});

it('shows internal DNS in the Node side menu and opens the page', function () {
    $this->get(route('node.show', $this->node->uuid))
        ->assertSuccessful()
        ->assertSee('Internal DNS')
        ->assertSee(route('node.internal-dns', $this->node->uuid), false);

    $this->get(route('node.internal-dns', $this->node->uuid))
        ->assertSuccessful()
        ->assertSeeLivewire(InternalDns::class)
        ->assertSee('web.default.coolify.internal')
        ->assertSee('10.240.0.2');
});

it('refreshes internal DNS through the authorized Node', function () {
    Livewire::test(InternalDns::class, ['node_uuid' => $this->node->uuid])
        ->call('refreshEndpoints')
        ->assertSet('endpoints.0.hostname', 'web.default.coolify.internal')
        ->assertDispatched('success', 'Internal DNS records refreshed.');

});

it('shows dotted IP owner keys and falls back to the running state without a health check', function () {
    Livewire::test(InternalDns::class, ['node_uuid' => $this->node->uuid])
        ->set('nodeNamesByAddress', ['10.240.0.2' => 'Worker Node A'])
        ->set('endpoints.0.health', 'unknown')
        ->set('endpoints.0.expires_at', now()->addMinutes(5)->timestamp)
        ->assertSee('Worker Node A')
        ->assertSee('Running')
        ->assertDontSee('Not reported')
        ->assertDontSee('Unknown Node');
});

it('does not expose a foreign Node internal DNS page', function () {
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignTeam->id,
        'private_key_id' => $this->node->private_key_id,
    ]);

    $this->get(route('node.internal-dns', $foreignNode->uuid))->assertNotFound();
});

it('shows a safe error when Corrosion cannot be read', function () {
    FetchNodeDiscoveryEndpoints::clearFake();
    FetchNodeDiscoveryEndpoints::shouldRun()->andThrow(new RuntimeException('secret SSH error'));

    Livewire::test(InternalDns::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Internal DNS is unavailable')
        ->assertSee('Could not read internal DNS records from this Node.')
        ->assertDontSee('secret SSH error');
});

it('keeps internal DNS behind the development feature gate', function () {
    config()->set('constants.sentinel.host_enabled', false);

    $this->get(route('node.internal-dns', $this->node->uuid))->assertNotFound();
});
