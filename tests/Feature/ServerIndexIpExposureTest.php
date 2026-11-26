<?php

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->server = Server::factory()->create([
        'name' => 'production-server',
        'ip' => '203.0.113.45',
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('server index does not expose server IP addresses', function () {
    Livewire::test(ServerIndex::class)
        ->assertSee('production-server')
        ->assertDontSee('203.0.113.45');
});

test('server index loads the address of a team server on demand', function () {
    Livewire::test(ServerIndex::class)
        ->call('loadServerAddress', $this->server->uuid)
        ->assertReturned('203.0.113.45');
});

test('server index does not load the address of another team server', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create([
        'ip' => '198.51.100.77',
        'team_id' => $otherTeam->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $otherTeam->id])->id,
    ]);

    Livewire::test(ServerIndex::class)
        ->call('loadServerAddress', $otherServer->uuid)
        ->assertReturned(null);
});
