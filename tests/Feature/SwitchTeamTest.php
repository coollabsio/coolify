<?php

use App\Livewire\SwitchTeam;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->currentTeam = $this->user->teams()->first();
    $this->otherTeam = Team::factory()->create();
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->currentTeam]);
});

test('switching teams keeps the current page URL', function () {
    $currentUrl = route('security.api-tokens', ['page' => 2]);

    Livewire::test(SwitchTeam::class)
        ->call('switch_to', $this->otherTeam->id, $currentUrl)
        ->assertRedirect($currentUrl);

    expect(session('currentTeam')->is($this->otherTeam))->toBeTrue();
});
