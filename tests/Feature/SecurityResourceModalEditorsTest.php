<?php

use App\Livewire\Security\CloudInitScript\Show as CloudInitScriptShow;
use App\Livewire\Security\PrivateKey\Index as PrivateKeyIndex;
use App\Livewire\Security\PrivateKey\Show as PrivateKeyShow;
use App\Models\CloudInitScript;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Once::flush();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->team = $team;
    Storage::fake('ssh-keys');
});

it('deletes a cloud-init script from its modal editor without redirecting to a detail page', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Docker host',
        'script' => "#cloud-config\npackages:\n  - curl\n",
    ]);

    Livewire::test(CloudInitScriptShow::class, [
        'cloud_init_script_uuid' => $script->uuid,
        'modalMode' => true,
    ])->call('delete')
        ->assertDispatched('securityResourceChanged')
        ->assertDispatched('close-modal');

    $this->assertModelMissing($script);
});

it('keeps the remaining private key editor populated after deleting multiple keys', function () {
    $privateKeys = collect(range(1, 3))->map(fn (int $index) => PrivateKey::factory()->create([
        'name' => "private-key-regression-marker-{$index}",
        'team_id' => $this->team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]));
    $index = Livewire::test(PrivateKeyIndex::class);

    foreach ($privateKeys->take(2) as $privateKey) {
        Livewire::test(PrivateKeyShow::class, [
            'private_key_uuid' => $privateKey->uuid,
            'modalMode' => true,
        ])->call('delete')
            ->assertDispatched('privateKeyDeleted')
            ->assertNoRedirect();
    }

    $remainingPrivateKey = $privateKeys->last();

    $index->dispatch('securityResourceChanged')
        ->assertDontSee($privateKeys->get(0)->name)
        ->assertDontSee($privateKeys->get(1)->name)
        ->assertSee($remainingPrivateKey->name);

    Livewire::test(PrivateKeyShow::class, [
        'private_key_uuid' => $remainingPrivateKey->uuid,
        'modalMode' => true,
    ])->assertSet('name', $remainingPrivateKey->name)
        ->assertSee($remainingPrivateKey->name);
});

it('loads only the selected private key editor and refreshes mutations without navigation', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Livewire::test(PrivateKeyIndex::class)
        ->call('openEditor', $privateKey->uuid)
        ->assertSet('selectedPrivateKeyUuid', $privateKey->uuid)
        ->dispatch('modalClosed')
        ->assertSet('selectedPrivateKeyUuid', null)
        ->dispatch('privateKeyCreated', keyId: $privateKey->id)
        ->assertNoRedirect();
});

it('loads the public key with the editor instead of making a follow-up request', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Livewire::test(PrivateKeyShow::class, [
        'private_key_uuid' => $privateKey->uuid,
        'modalMode' => true,
    ])->assertSet('public_key', $privateKey->getPublicKey());
});
