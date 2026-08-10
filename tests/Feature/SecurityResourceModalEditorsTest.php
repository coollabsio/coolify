<?php

use App\Livewire\Security\CloudInitScript\Show as CloudInitScriptShow;
use App\Models\CloudInitScript;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->toContain('@click="modalOpen=true"')
            ->not->toContain('wire:click="openEditor(')
            ->not->toContain('href="{{ route(\'security.');
    }

    expect(file_get_contents($views[0]))->toContain('>Private key</div>', '>Status</div>');
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
