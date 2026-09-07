<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

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
    test('legacy team endpoints are restricted to the token team', function () {
        $otherTeam = Team::factory()->create(['name' => 'Other Team']);
        $otherMember = User::factory()->create();
        $otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
        $otherTeam->members()->attach($otherMember->id, ['role' => 'member']);

        $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson('/api/v1/teams')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->team->id);

        $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson("/api/v1/teams/{$otherTeam->id}")
            ->assertNotFound();

        $this->withHeaders(teamTokenApiHeaders($this->bearerToken))
            ->getJson("/api/v1/teams/{$otherTeam->id}/members")
            ->assertNotFound();
    });

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
