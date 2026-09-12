<?php

use App\Livewire\Server\Index;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->user->teams()->firstOrFail()]);
});

it('shows team nodes in the servers view and links to the node page', function () {
    $node = Node::factory()->create([
        'team_id' => $this->user->teams()->firstOrFail()->id,
        'name' => 'QEMU worker node',
        'uuid' => 'development-qemu-node-worker',
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Nodes')
        ->assertSee('QEMU worker node')
        ->assertSee('Worker')
        ->assertSee(route('node.show', ['node_uuid' => $node->uuid]), escape: false);
});

it('does not show nodes from another team', function () {
    $otherUser = User::factory()->create();
    Node::factory()->create([
        'team_id' => $otherUser->teams()->firstOrFail()->id,
        'name' => 'Private foreign node',
    ]);

    Livewire::test(Index::class)->assertDontSee('Private foreign node');
});

it('does not show the development node section when the feature gate is disabled', function () {
    Node::factory()->create([
        'team_id' => $this->user->teams()->firstOrFail()->id,
        'name' => 'Hidden QEMU node',
    ]);
    config()->set('constants.sentinel.host_enabled', false);

    Livewire::test(Index::class)
        ->assertDontSee('Hidden QEMU node')
        ->assertDontSee('Nodes');
});
