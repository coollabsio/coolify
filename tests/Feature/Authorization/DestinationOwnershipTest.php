<?php

use App\Livewire\Destination\Show;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    // Team A owns the destination
    $this->teamA = Team::factory()->create();
    $this->userA = User::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'admin']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Key A',
        'private_key' => 'test-key-a',
        'team_id' => $this->teamA->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->teamA->id,
        'private_key_id' => $keyId,
    ]);

    // Use the StandaloneDocker created by the Server factory
    $this->destination = $this->server->standaloneDockers()->first();

    // Team B is a different team
    $this->teamB = Team::factory()->create();
    $this->userB = User::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'admin']);
});

test('team member can view their own destination', function () {
    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);

    Livewire::test(Show::class, ['destination_uuid' => $this->destination->uuid])
        ->assertSet('name', $this->destination->name);
});

test('cross-team user cannot view destination', function () {
    $this->actingAs($this->userB);
    session(['currentTeam' => $this->teamB]);

    Livewire::test(Show::class, ['destination_uuid' => $this->destination->uuid])
        ->assertStatus(403);
});
