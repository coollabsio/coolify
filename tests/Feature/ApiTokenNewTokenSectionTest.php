<?php

use App\Livewire\Security\ApiTokens;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $team]);
});

test('the freshly created token is announced with a non-imperative heading', function () {
    Livewire::test(ApiTokens::class)
        ->set('description', 'ci-token')
        ->set('permissions', ['read'])
        ->call('addNewToken')
        ->assertSee('Your new token')
        ->assertDontSee('Copy your token')
        ->assertSee('This value will not be shown again after you leave this page.');
});
