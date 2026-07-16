<?php

use App\Livewire\Security\CloudProviderTokenForm;
use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('stores OpenStack credentials as a decodable JSON blob when authentication succeeds', function () {
    Http::fake([
        'identity.example/v3/auth/tokens' => Http::response(['token' => ['catalog' => []]], 201, ['X-Subject-Token' => 'tok']),
    ]);

    Livewire::test(CloudProviderTokenForm::class, ['modal_mode' => true, 'provider' => 'openstack'])
        ->set('name', 'My Cloud')
        ->set('os_auth_url', 'https://identity.example/v3/')
        ->set('os_application_credential_id', 'app-id')
        ->set('os_application_credential_secret', 'app-secret')
        ->set('os_region', 'RegionOne')
        ->call('addToken')
        ->assertHasNoErrors();

    $token = CloudProviderToken::where('provider', 'openstack')->first();

    expect($token)->not->toBeNull();

    $credentials = $token->credentials();

    expect($credentials['auth_url'])->toBe('https://identity.example/v3')
        ->and($credentials['application_credential_id'])->toBe('app-id')
        ->and($credentials['application_credential_secret'])->toBe('app-secret')
        ->and($credentials['region'])->toBe('RegionOne');
});

it('does not store OpenStack credentials when authentication fails', function () {
    Http::fake([
        'identity.example/v3/auth/tokens' => Http::response(['error' => ['message' => 'bad']], 401),
    ]);

    Livewire::test(CloudProviderTokenForm::class, ['modal_mode' => true, 'provider' => 'openstack'])
        ->set('name', 'My Cloud')
        ->set('os_auth_url', 'https://identity.example/v3')
        ->set('os_application_credential_id', 'app-id')
        ->set('os_application_credential_secret', 'wrong')
        ->call('addToken');

    expect(CloudProviderToken::where('provider', 'openstack')->exists())->toBeFalse();
});

it('validates required OpenStack credential fields', function () {
    Livewire::test(CloudProviderTokenForm::class, ['modal_mode' => true, 'provider' => 'openstack'])
        ->set('name', 'My Cloud')
        ->call('addToken')
        ->assertHasErrors(['os_auth_url', 'os_application_credential_id', 'os_application_credential_secret']);
});
