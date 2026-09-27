<?php

use App\Livewire\Security\IntegrationTokenForm;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

test('a doppler token is validated against the doppler api before it is saved', function () {
    Http::fake([
        'https://api.doppler.com/v3/me' => Http::response(['type' => 'service_token']),
    ]);

    Livewire::test(IntegrationTokenForm::class, ['modal_mode' => true])
        ->set('provider', 'doppler')
        ->set('name', 'Production secrets')
        ->set('token', 'dp.st.token')
        ->call('addToken')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    $this->assertDatabaseHas('integration_tokens', [
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Production secrets',
    ]);
});

test('selecting a secret manager provider switches the capability to secrets', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'doppler')
        ->assertSet('capabilities', ['secrets'])
        ->set('provider', 'cloudflare')
        ->assertSet('capabilities', ['dns']);
});

test('an invalid doppler token is not saved', function () {
    Http::fake([
        'https://api.doppler.com/v3/me' => Http::response([], 401),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'doppler')
        ->set('name', 'Bad token')
        ->set('token', 'dp.st.rejected')
        ->call('addToken')
        ->assertHasNoErrors()
        ->assertDispatched('error');

    $this->assertDatabaseCount('integration_tokens', 0);
});

test('doppler only accepts service and service account tokens', function (string $token) {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'doppler')
        ->set('name', 'Unsupported token')
        ->set('token', $token)
        ->call('addToken')
        ->assertHasErrors(['token']);

    $this->assertDatabaseCount('integration_tokens', 0);
})->with([
    'personal token' => 'dp.pt.token',
    'unknown token' => 'token',
]);

test('a doppler service account token is accepted', function () {
    Http::fake([
        'https://api.doppler.com/v3/me' => Http::response(['type' => 'service_account']),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'doppler')
        ->set('name', 'Shared secrets')
        ->set('token', 'dp.sa.token')
        ->call('addToken')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integration_tokens', [
        'provider' => 'doppler',
        'name' => 'Shared secrets',
    ]);
});

test('an infisical token requires a base url and a client id', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'infisical')
        ->set('name', 'Infisical')
        ->set('token', 'client-secret')
        ->set('metadata', [])
        ->call('addToken')
        ->assertHasErrors(['metadata.base_url', 'metadata.client_id']);

    $this->assertDatabaseCount('integration_tokens', 0);
});

test('secret manager provider base urls only accept http and https', function (string $provider, array $metadata) {
    Http::fake();

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', $provider)
        ->set('name', 'Invalid base URL')
        ->set('token', 'token')
        ->set('metadata', $metadata)
        ->call('addToken')
        ->assertHasErrors(['metadata.base_url']);

    Http::assertNothingSent();
    $this->assertDatabaseCount('integration_tokens', 0);
})->with([
    'infisical' => ['infisical', ['base_url' => 'ftp://infisical.example.com', 'client_id' => 'client-1']],
    'vault' => ['vault', ['base_url' => 'ftp://vault.example.com']],
]);

test('the infisical fields put the client id before the client secret', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'infisical')
        ->assertSeeInOrder(['Token name', 'Client ID', 'Client secret', 'Base URL']);
});

test('an infisical token stores its metadata after a successful login', function () {
    Http::fake([
        'https://example.com/api/v1/auth/universal-auth/login' => Http::response([
            'accessToken' => 'token',
        ]),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'infisical')
        ->set('name', 'Infisical')
        ->set('token', 'client-secret')
        ->set('metadata', ['base_url' => 'https://example.com', 'client_id' => 'client-1'])
        ->call('addToken')
        ->assertHasNoErrors();

    $token = IntegrationToken::query()->where('provider', 'infisical')->firstOrFail();

    expect($token->metadata)->toBe(['base_url' => 'https://example.com', 'client_id' => 'client-1'])
        ->and($token->capabilities)->toBe(['secrets']);
});

test('a vault token is validated with lookup-self before it is saved', function () {
    Http::fake([
        'https://example.com:8200/v1/auth/token/lookup-self' => Http::response(['data' => []]),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'vault')
        ->set('name', 'Vault')
        ->set('token', 'hvs.token')
        ->set('metadata', ['base_url' => 'https://example.com:8200'])
        ->call('addToken')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integration_tokens', [
        'provider' => 'vault',
        'name' => 'Vault',
    ]);
});

test('the dns capability is rejected for secret manager providers', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'doppler')
        ->set('name', 'Doppler')
        ->set('token', 'dp.st.token')
        ->set('capabilities', ['dns'])
        ->call('addToken')
        ->assertHasErrors(['capabilities.0']);

    $this->assertDatabaseCount('integration_tokens', 0);
});
