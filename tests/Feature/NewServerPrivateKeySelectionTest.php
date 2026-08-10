<?php

use App\Livewire\Server\New\ByIp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Storage::fake('ssh-keys');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->actingAs($this->user);

    $this->existingPrivateKey = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'Existing SSH Key',
        'description' => 'Existing SSH Key',
        'private_key' => 'test-private-key',
        'team_id' => $this->team->id,
    ]));
});

it('generates and preselects a new private key without clearing server form data', function () {
    $component = Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->existingPrivateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'Production Server')
        ->set('description', 'Deploy target')
        ->set('ip', '192.0.2.50')
        ->set('user', 'deploy.user')
        ->set('port', 2222)
        ->set('is_build_server', true)
        ->call('generatePrivateKey', 'ed25519')
        ->assertHasNoErrors()
        ->assertSet('name', 'Production Server')
        ->assertSet('description', 'Deploy target')
        ->assertSet('ip', '192.0.2.50')
        ->assertSet('user', 'deploy.user')
        ->assertSet('port', 2222)
        ->assertSet('is_build_server', true);

    $newPrivateKeyId = $component->get('private_key_id');

    expect($newPrivateKeyId)->not->toBe($this->existingPrivateKey->id)
        ->and(PrivateKey::find($newPrivateKeyId))->not->toBeNull()
        ->and($component->get('private_keys')->pluck('id'))->toContain($newPrivateKeyId);
});

it('preselects a manually added private key without clearing server form data', function () {
    $manualPrivateKey = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'Manual SSH Key',
        'description' => 'Manual SSH Key',
        'private_key' => 'manual-test-private-key',
        'team_id' => $this->team->id,
    ]));

    Livewire::test(ByIp::class, [
        'private_keys' => collect([$this->existingPrivateKey]),
        'limit_reached' => false,
    ])
        ->set('name', 'Production Server')
        ->set('description', 'Deploy target')
        ->set('ip', '192.0.2.51')
        ->set('user', 'deploy.user')
        ->set('port', 2222)
        ->set('is_build_server', true)
        ->call('handlePrivateKeyCreated', $manualPrivateKey->id)
        ->assertSet('private_key_id', $manualPrivateKey->id)
        ->assertSet('name', 'Production Server')
        ->assertSet('description', 'Deploy target')
        ->assertSet('ip', '192.0.2.51')
        ->assertSet('user', 'deploy.user')
        ->assertSet('port', 2222)
        ->assertSet('is_build_server', true)
        ->assertSee('Manual SSH Key');
});
