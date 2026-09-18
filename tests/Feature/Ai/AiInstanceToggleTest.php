<?php

use App\Livewire\Settings\Ai;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('instance admin can enable the ai assistant', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Ai::class)
        ->assertSet('is_ai_assistant_enabled', false)
        ->set('is_ai_assistant_enabled', true)
        ->call('instantSave')
        ->assertHasNoErrors();

    Once::flush();
    expect(InstanceSettings::get()->is_ai_assistant_enabled)->toBeTrue();
});
