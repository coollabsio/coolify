<?php

use App\Livewire\Server\CloudProviderToken\Show;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

test('server cloud provider token cards show token descriptions', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'hetzner_server_id' => 12345,
    ]);

    CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'name' => 'Production Hetzner',
        'description' => 'Used for production servers in the EU region.',
    ]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->assertSee('Production Hetzner')
        ->assertSee('Used for production servers in the EU region.');
});
