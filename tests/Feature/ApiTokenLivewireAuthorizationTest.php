<?php

use App\Livewire\Security\ApiTokens;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
});

test('api token permission flags are locked', function (string $property) {
    $property = new ReflectionProperty(ApiTokens::class, $property);

    expect($property->getAttributes(Locked::class))->not->toBeEmpty();
})->with([
    'root permission flag' => 'canUseRootPermissions',
    'write permission flag' => 'canUseWritePermissions',
]);

test('member cannot tamper with root permission flag', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->set('canUseRootPermissions', true);
})->throws(CannotUpdateLockedPropertyException::class);

test('member cannot create root token through tampered permissions payload', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->set('description', 'pwned-root-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['root'])
        ->call('addNewToken');

    expect($member->tokens()->count())->toBe(0);
});

test('member can still create read token', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->set('description', 'read-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['read'])
        ->call('addNewToken')
        ->assertHasNoErrors();

    $token = $member->tokens()->latest()->first();

    expect($token)->not->toBeNull()
        ->and($token->abilities)->toBe(['read']);
});

test('owner can create root token', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->set('description', 'root-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['root'])
        ->call('addNewToken')
        ->assertHasNoErrors();

    $token = $owner->tokens()->latest()->first();

    expect($token)->not->toBeNull()
        ->and($token->abilities)->toBe(['root']);
});
