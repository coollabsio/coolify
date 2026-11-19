<?php

use App\Livewire\Server\CreatePage;
use App\Livewire\Server\New\ByDigitalOcean;
use App\Livewire\Server\New\ByHetzner;
use App\Livewire\Server\New\ByVultr;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('provider token selection redirects to a token specific server creation url', function (string $component, string $provider, string $type) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Livewire::test($component)
        ->call('selectToken', $token->id)
        ->assertRedirect(route('server.create.token', [
            'type' => $type,
            'token_uuid' => $token->uuid,
        ]));
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner', 'hetzner'],
    'vultr' => [ByVultr::class, 'vultr', 'vultr'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'digital-ocean'],
]);

test('provider token urls pass the token uuid into the server creation page', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
    ]);

    expect(route('server.create.token', [
        'type' => 'hetzner',
        'token_uuid' => $token->uuid,
    ]))->toEndWith('/servers/new/hetzner/'.$token->uuid);

    Livewire::test(CreatePage::class, [
        'type' => 'hetzner',
        'token_uuid' => $token->uuid,
    ])
        ->assertSet('type', 'hetzner')
        ->assertSet('token_uuid', $token->uuid)
        ->assertSet('title', 'Hetzner');
});

test('provider token pages do not load provider api data on mount', function (string $component, string $provider) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    Livewire::test($component, ['selectedTokenUuid' => $token->uuid])
        ->assertSet('current_step', 2)
        ->assertSet('loading_data', true);

    Http::assertNothingSent();
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner'],
    'vultr' => [ByVultr::class, 'vultr'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean'],
]);

test('provider token pages surface api error details when a selected token is invalid', function (string $component, string $provider, string $loadMethod, string $providerName, string $apiMessage, array $responseBody) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
        'name' => $providerName.' Token',
    ]);

    Http::fake([
        '*' => Http::response($responseBody, 401),
    ]);

    Livewire::test($component, ['selectedTokenUuid' => $token->uuid])
        ->call($loadMethod)
        ->assertSet('loading_data', false)
        ->assertSet('provider_data_error', "{$providerName} API error: {$apiMessage}")
        ->assertDispatched('error')
        ->assertSee($apiMessage);
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner', 'loadHetznerData', 'Hetzner', 'the token you have provided is invalid', ['error' => ['code' => 'unauthorized', 'message' => 'the token you have provided is invalid']]],
    'vultr' => [ByVultr::class, 'vultr', 'loadVultrData', 'Vultr', 'Invalid API key', ['error' => 'Invalid API key', 'status' => 401]],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'loadDigitalOceanData', 'DigitalOcean', 'Unable to authenticate you', ['id' => 'Unauthorized', 'message' => 'Unable to authenticate you']],
]);
