<?php

use App\Livewire\Security\CloudInitScript\Show;
use App\Livewire\Security\CloudInitScriptForm;
use App\Livewire\Security\CloudInitScripts;
use App\Models\CloudInitScript;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('cloud-init scripts page lists every supported cloud provider integration', function () {
    Livewire::test(CloudInitScripts::class)
        ->assertSee('Hetzner')
        ->assertSee('Vultr')
        ->assertSee('DigitalOcean')
        ->assertDontSee('Currently working only with Hetzner');
});

test('cloud-init script form does not show a cancel button in the modal', function () {
    Livewire::test(CloudInitScriptForm::class)
        ->assertSee('Create Script')
        ->assertDontSee('Cancel');
});

test('cloud-init script cards link to the script detail page without inline actions or created time', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Docker Host Setup',
        'script' => "#cloud-config\npackages:\n  - htop\n",
    ]);

    Livewire::test(CloudInitScripts::class)
        ->assertSee('Docker Host Setup')
        ->assertSee(route('security.cloud-init-scripts.show', ['cloud_init_script_uuid' => $script->uuid]), false)
        ->assertDontSee('Created')
        ->assertDontSee('Edit')
        ->assertDontSee('Delete');
});

test('cloud-init script detail page shows editable script fields', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Docker Host Setup',
        'script' => "#cloud-config\npackages:\n  - htop\n",
    ]);

    $this->get(route('security.cloud-init-scripts.show', ['cloud_init_script_uuid' => $script->uuid]))
        ->assertSuccessful()
        ->assertSee('Cloud-Init Script')
        ->assertSee('Docker Host Setup')
        ->assertSee('Save')
        ->assertSee('Delete');
});

test('cloud-init script detail page updates a script', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Docker Host Setup',
        'script' => "#cloud-config\npackages:\n  - htop\n",
    ]);

    Livewire::test(Show::class, ['cloud_init_script_uuid' => $script->uuid])
        ->set('name', 'Updated Host Setup')
        ->set('script', "#cloud-config\npackages:\n  - curl\n")
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $script->refresh();

    expect($script->name)->toBe('Updated Host Setup')
        ->and($script->script)->toContain('curl');
});

test('cloud-init script detail page deletes a script', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Docker Host Setup',
        'script' => "#cloud-config\npackages:\n  - htop\n",
    ]);

    Livewire::test(Show::class, ['cloud_init_script_uuid' => $script->uuid])
        ->call('delete')
        ->assertRedirectToRoute('security.cloud-init-scripts');

    $this->assertModelMissing($script);
});

test('cloud-init scripts page backfills missing script uuids before rendering links', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Legacy Script',
        'script' => "#cloud-config\npackages:\n  - htop\n",
    ]);

    CloudInitScript::query()->whereKey($script->id)->update(['uuid' => null]);

    Livewire::test(CloudInitScripts::class)
        ->assertSee('Legacy Script');

    expect($script->refresh()->uuid)->not->toBeNull();
});
