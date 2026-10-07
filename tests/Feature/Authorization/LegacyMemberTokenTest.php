<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->admin = User::factory()->create();

    $this->team->members()->attach($this->member->id, ['role' => 'member']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    session(['currentTeam' => $this->team]);
});

function apiRequest($test, string $token, string $method = 'get', string $url = '/api/v1/version')
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ])->{$method.'Json'}($url);
}

/**
 * The api.token.team middleware rejects elevated abilities on member tokens
 * before ApiAbility runs, so REST clients get its generic role message.
 */
describe('member with legacy elevated token is rejected', function () {
    test('member with legacy elevated token gets 403', function (array $abilities) {
        $token = $this->member->createToken('legacy-elevated', $abilities);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'Missing required team role.']);
    })->with([
        'write' => [['read', 'write']],
        'deploy' => [['read', 'deploy']],
        'root' => [['root']],
        'read:sensitive' => [['read', 'read:sensitive']],
        'write:sensitive' => [['read', 'write:sensitive']],
        'multiple' => [['read', 'write', 'deploy', 'read:sensitive']],
    ]);

    test('member with legacy elevated token cannot reach a write endpoint', function () {
        $token = $this->member->createToken('legacy-write', ['read', 'write']);

        $response = apiRequest($this, $token->plainTextToken, 'patch', '/api/v1/team');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'Missing required team role.']);
    });
});

describe('member with read-only token passes through', function () {
    test('member with read token can access read endpoints', function () {
        $token = $this->member->createToken('read-only', ['read']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });
});

describe('admin with elevated token passes through', function () {
    test('admin with write token is not blocked', function () {
        $token = $this->admin->createToken('admin-write', ['read', 'write']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });

    test('admin with root token is not blocked', function () {
        $token = $this->admin->createToken('admin-root', ['root']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });

    test('admin with deploy token is not blocked', function () {
        $token = $this->admin->createToken('admin-deploy', ['read', 'deploy']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });
});
