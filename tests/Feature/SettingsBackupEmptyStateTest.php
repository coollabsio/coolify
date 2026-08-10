<?php

use App\Livewire\SettingsBackup;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('empty component renders the actions slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Backup is not configured" description="Needs a database." size="sm">
            <x-slot:actions>
                <button type="button">Configure backup</button>
            </x-slot:actions>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('Backup is not configured')
        ->toContain('Configure backup');
});

test('empty component renders the contents slot for backward compatibility', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Metrics are disabled" size="sm">
            <x-slot:contents>
                <button type="button">Enable metrics</button>
            </x-slot:contents>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('Metrics are disabled')
        ->toContain('Enable metrics');
});

test('empty component prefers the actions slot when both action slots are provided', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty title="Conflict check" size="sm">
            <x-slot:actions>
                <button type="button">From actions</button>
            </x-slot:actions>
            <x-slot:contents>
                <button type="button">From contents</button>
            </x-slot:contents>
        </x-empty>
    BLADE);

    expect($html)
        ->toContain('From actions')
        ->not->toContain('From contents');
});

test('instance backup settings show a configure button when backup is not set up', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $rootTeam->id,
        'ip' => '127.0.0.1',
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(SettingsBackup::class)
        ->assertOk()
        ->assertSee('Backup is not configured')
        ->assertSee('Configure backup')
        ->assertSeeHtml('wire:click="addCoolifyDatabase"');
});
