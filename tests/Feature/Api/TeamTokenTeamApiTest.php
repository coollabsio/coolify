<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create(['name' => 'Token Team']);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('team-token-api-test', ['*'])->plainTextToken;
});

function teamTokenApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Accept' => 'application/json',
    ];
}

describe('token team endpoints', function () {
    test('GET /team returns the token team', function () {
        $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonPath('id', $this->team->id)
            ->assertJsonPath('name', 'Token Team');
    });

    test('GET /team/members returns members of the token team', function () {
        $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/team/members')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->user->id]);
    });

    test('deprecated GET /teams/current aliases GET /team', function () {
        $preferred = $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/team')
            ->assertOk()
            ->json();

        $alias = $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/teams/current')
            ->assertOk()
            ->json();

        expect($alias)->toBe($preferred);
    });

    test('deprecated GET /teams/current/members aliases GET /team/members', function () {
        $preferred = $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/team/members')
            ->assertOk()
            ->json();

        $alias = $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/teams/current/members')
            ->assertOk()
            ->json();

        expect($alias)->toBe($preferred);
    });
});
