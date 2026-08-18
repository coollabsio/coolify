<?php

use App\Livewire\Security\ApiTokens;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

describe('token creation with expiration', function () {
    test('livewire component stores expires_at when expiresInDays set', function () {
        Livewire::test(ApiTokens::class)
            ->set('description', 'test-token')
            ->set('expiresInDays', 7)
            ->set('permissions', ['read'])
            ->call('addNewToken')
            ->assertHasNoErrors();

        $token = $this->user->tokens()->latest()->first();

        expect($token)->not->toBeNull()
            ->and($token->expires_at)->not->toBeNull()
            ->and($token->expires_at->diffInDays(now()))->toBeGreaterThanOrEqual(6)
            ->and($token->expires_at->diffInDays(now()))->toBeLessThanOrEqual(7);
    });

    test('livewire component stores null expires_at when expiresInDays null (Never)', function () {
        Livewire::test(ApiTokens::class)
            ->set('description', 'never-token')
            ->set('expiresInDays', null)
            ->set('permissions', ['read'])
            ->call('addNewToken')
            ->assertHasNoErrors();

        $token = $this->user->tokens()->latest()->first();

        expect($token)->not->toBeNull()
            ->and($token->expires_at)->toBeNull();
    });

    test('livewire component rejects invalid expiresInDays value', function () {
        Livewire::test(ApiTokens::class)
            ->set('description', 'bad-token')
            ->set('expiresInDays', 42)
            ->set('permissions', ['read'])
            ->call('addNewToken')
            ->assertHasErrors('expiresInDays');
    });
});

describe('expired token rejected on API', function () {
    test('request with expired token returns 401', function () {
        $token = $this->user->createToken('expired', ['read'], now()->subDay());

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/projects');

        $response->assertStatus(401);
    });

    test('request with non-expired token works', function () {
        $token = $this->user->createToken('valid', ['read'], now()->addDay());

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/projects');

        $response->assertStatus(200);
    });
});
