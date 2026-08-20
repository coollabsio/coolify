<?php

use App\Livewire\Team\Create as TeamCreate;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create(['personal_team' => false]);
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
});

test('creating a team sets is_mcp_server_enabled to true on the model', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamCreate::class)
        ->set('name', 'MCP Safe Team')
        ->set('description', 'Ensures MCP default is set')
        ->call('submit')
        ->assertHasNoErrors();

    $created = Team::query()->where('name', 'MCP Safe Team')->first();

    expect($created)->not->toBeNull()
        ->and($created->is_mcp_server_enabled)->toBeTrue()
        ->and($this->user->fresh()->roleInTeam($created->id))->toBe('owner');
});
