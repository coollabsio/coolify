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

it('opens security resources in modal editors and keeps create actions in card headers', function () {
    $views = [
        resource_path('views/livewire/security/private-key/index.blade.php'),
        resource_path('views/livewire/security/cloud-provider-tokens.blade.php'),
        resource_path('views/livewire/security/cloud-init-scripts.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('<x-application.settings-section')
            ->toContain('<x-slot:actions>')
            ->toContain('<x-modal-input title="Edit')
            ->toContain('<x-reicon name="settings"')
            ->toContain(':contentClicks="false"')
            ->not->toContain('href="{{ route(\'security.');
    }

    expect(file_get_contents($views[0]))
        ->toContain('>Private key</div>', '>Status</div>')
        ->toContain('wire:click="openEditor(\'{{ $key->uuid }}\')"')
        ->toContain("\$dispatch('open-private-key-editor', { name:")
        ->toContain('$refs.loadingPrivateKeyName.value = $event.detail.name')
        ->toContain('wire:loading.flex wire:target="openEditor"')
        ->toContain('aria-label="Loading private key editor"')
        ->toContain('class="w-full flex-col gap-4"')
        ->toContain('<x-forms.input label="Public key" loading')
        ->toContain('<x-forms.input loading :allowToPeak="false" />')
        ->toContain('<x-forms.input label="Name" required x-ref="loadingPrivateKeyName" />')
        ->toContain('<x-forms.input label="Description" x-ref="loadingPrivateKeyDescription" />')
        ->not->toContain('class="flex flex-col gap-1.5 lg:col-span-2"')
        ->not->toContain('animate-pulse')
        ->and(substr_count(file_get_contents($views[0]), '<livewire:security.private-key.show'))->toBe(1);
    expect(file_get_contents($views[1]))->toContain('>Token</div>', '>Provider</div>');
    expect(file_get_contents($views[2]))->toContain('>Script</div>', '>Last updated</div>');

    expect(substr_count(file_get_contents($views[0]), 'sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem]'))->toBeGreaterThanOrEqual(2);
    expect(substr_count(file_get_contents($views[1]), 'sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_1.75rem]'))->toBeGreaterThanOrEqual(2);
    expect(substr_count(file_get_contents($views[2]), 'grid-cols-[minmax(0,1fr)_12rem_1.75rem]'))->toBeGreaterThanOrEqual(2);
    expect(file_get_contents($views[2]))
        ->toContain('<div>Last updated</div>')
        ->toContain('<div class="flex items-center">')
        ->not->toContain('<div class="text-right">Last updated</div>');

    foreach ($views as $view) {
        expect(file_get_contents($view))
            ->toContain('grid-cols-[')
            ->toContain('items-center gap-3')
            ->toContain('class="pl-11"')
            ->toContain('text-[13px] font-medium');
    }
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

    expect(file_get_contents(resource_path('views/livewire/security/private-key/show.blade.php')))
        ->not->toContain('x-init="$wire.loadPublicKey()"');
});

it('shows deletion progress in the underlying private key editor', function () {
    $view = file_get_contents(resource_path('views/livewire/security/private-key/show.blade.php'));

    expect($view)
        ->toContain('wire:loading.class="pointer-events-none opacity-50" wire:target="delete"')
        ->toContain('wire:loading.flex wire:target="delete"')
        ->toContain('<x-loading text="Deleting private key..." />');
});
