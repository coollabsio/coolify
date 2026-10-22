<?php

use App\Livewire\Server\CloudProviderToken\Show;
use App\Models\AuditEvent;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

test('server cloud provider token page supports Hostinger VPS servers', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'hostinger_virtual_machine_id' => 17923,
    ]);

    CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'name' => 'Production Hostinger',
    ]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->assertSet('provider', 'hostinger')
        ->assertSet('providerName', 'Hostinger')
        ->assertSee('Production Hostinger');
});

describe('after the user switches the session team', function () {
    beforeEach(function () {
        $this->withoutDefer();
        Http::fake(['*' => Http::response(['servers' => []])]);

        $this->otherTeam = Team::factory()->create();
        $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
        $this->server = Server::factory()->create(['team_id' => $this->team->id]);
        $this->sameTeamToken = CloudProviderToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'hetzner']);
        $this->otherTeamToken = CloudProviderToken::factory()->create(['team_id' => $this->otherTeam->id, 'provider' => 'hetzner']);

        $this->component = Livewire::test(Show::class, ['server_uuid' => $this->server->uuid]);
        session(['currentTeam' => $this->otherTeam]);
    });

    test('lists only tokens of the server team', function () {
        $tokenIds = $this->component->call('loadTokens')->get('cloudProviderTokens')->pluck('id')->all();

        expect($tokenIds)->toBe([$this->sameTeamToken->id]);
    });

    test('rejects a token of the session team', function () {
        $this->component->call('setCloudProviderToken', $this->otherTeamToken->id)
            ->assertDispatched('error', 'You are not allowed to use this token.');

        expect($this->server->fresh()->cloud_provider_token_id)->toBeNull();
    });

    test('accepts a token of the server team and audits it for the server team', function () {
        $this->component->call('setCloudProviderToken', $this->sameTeamToken->id)
            ->assertDispatched('success');

        expect($this->server->fresh()->cloud_provider_token_id)->toBe($this->sameTeamToken->id)
            ->and(AuditEvent::query()->where('event', 'ui.server.cloud_token_assigned')->sole()->team_id)->toBe($this->team->id);
    });
});
