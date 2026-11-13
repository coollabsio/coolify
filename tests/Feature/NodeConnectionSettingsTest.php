<?php

use App\Helpers\SshMultiplexingHelper;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    setNodesEnabled(true);
    $this->owner = User::factory()->create();
    $this->team = $this->owner->teams()->firstOrFail();
    $this->node = Node::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('saves node connection settings', function () {
    $this->node->update(['settings' => ['is_metrics_enabled' => false]]);
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->set('connection.name', 'Updated node')
        ->set('connection.port', 2222)
        ->set('connection.settings.connection_timeout', 30)
        ->set('connection.settings.server_timezone', 'Europe/Budapest')
        ->set('connection.settings.wildcard_domain', 'https://example.com')
        ->call('saveConnection')->assertHasNoErrors();

    expect($this->node->fresh()->name)->toBe('Updated node')
        ->and($this->node->fresh()->port)->toBe(2222)
        ->and($this->node->fresh()->settings['connection_timeout'])->toBe(30)
        ->and($this->node->fresh()->isMetricsEnabled())->toBeFalse()
        ->and(SshMultiplexingHelper::getConnectionTimeout($this->node->fresh()))->toBe(30);
});

it('rejects invalid connection settings', function () {
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->set('connection.port', 0)
        ->set('connection.settings.server_timezone', 'invalid')
        ->call('saveConnection')->assertHasErrors(['connection.port', 'connection.settings.server_timezone']);
});

it('denies saving after switching to a member of the resource team', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $component = Livewire::test(Show::class, ['node_uuid' => $this->node->uuid]);
    $this->actingAs($member);
    session(['currentTeam' => $member->teams()->firstOrFail()]);
    $component->set('connection.name', 'Forbidden')->call('saveConnection')->assertForbidden();
    expect($this->node->fresh()->name)->not->toBe('Forbidden');
});

it('denies opening a node owned by another team', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $outsider->teams()->firstOrFail()]);
    expect(fn () => Livewire::test(Show::class, ['node_uuid' => $this->node->uuid]))
        ->toThrow(ModelNotFoundException::class);
});
