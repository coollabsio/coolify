<?php

use App\Livewire\Security\CloudProviderTokenForm;
use App\Livewire\Security\CloudProviderTokens;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    Log::spy();
});

test('adding a digitalocean token from a modal closes the modal and refreshes digitalocean token selectors', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response([], 200),
    ]);

    $component = Livewire::test(CloudProviderTokenForm::class, [
        'modal_mode' => true,
        'provider' => 'digitalocean',
    ])
        ->set('name', 'Production DigitalOcean')
        ->set('token', 'digitalocean-token')
        ->call('addToken')
        ->assertDispatched('close-modal');

    $dispatches = collect(data_get($component->effects, 'dispatches', []));

    expect($dispatches->contains(fn (array $dispatch) => $dispatch['name'] === 'tokenAdded.digitalocean'
        && data_get($dispatch, 'to') === 'server.new.by-digital-ocean'))->toBeTrue()
        ->and($dispatches->contains(fn (array $dispatch) => $dispatch['name'] === 'tokenAdded'
            && data_get($dispatch, 'to') === 'security.cloud-provider-tokens'))->toBeTrue();
});

test('adding a cloud provider token stores an optional description', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers' => Http::response([], 200),
    ]);

    Livewire::test(CloudProviderTokenForm::class)
        ->set('provider', 'hetzner')
        ->set('name', 'Production Hetzner')
        ->set('description', 'Used for production servers in the EU region.')
        ->set('token', 'hetzner-token')
        ->call('addToken')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cloud_provider_tokens', [
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'name' => 'Production Hetzner',
        'description' => 'Used for production servers in the EU region.',
    ]);
});

test('saved cloud provider tokens show descriptions when present', function () {
    CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Production Hetzner',
        'description' => 'Used for production servers in the EU region.',
    ]);

    Livewire::test(CloudProviderTokens::class)
        ->assertSee('Production Hetzner')
        ->assertSee('Used for production servers in the EU region.');
});
