<?php

use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

it('lets an instance admin turn passkeys off and keeps stored passkeys', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $admin = User::factory()->create();
    $rootTeam->members()->attach($admin->id, ['role' => 'owner']);
    $admin->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-1',
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(Advanced::class)
        ->assertSet('is_passkey_enabled', true)
        ->set('is_passkey_enabled', false)
        ->call('instantSave');

    expect(InstanceSettings::findOrFail(0)->is_passkey_enabled)->toBeFalse()
        ->and($admin->passkeys()->count())->toBe(1);
});
