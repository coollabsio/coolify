<?php

use App\Livewire\Security\CloudProviderToken\Show;
use App\Livewire\Security\CloudProviderTokens;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
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

test('saved cloud token cards link to the token detail page', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Production Hetzner',
    ]);

    Livewire::test(CloudProviderTokens::class)
        ->assertSee(route('security.cloud-tokens.show', ['cloud_token_uuid' => $token->uuid]), false);
});

test('cloud token detail page shows editable name and description fields', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Production Hetzner',
        'description' => 'Used for production servers.',
    ]);

    $this->get(route('security.cloud-tokens.show', ['cloud_token_uuid' => $token->uuid]))
        ->assertSuccessful()
        ->assertSee('Cloud Token')
        ->assertSee('Production Hetzner')
        ->assertSee('Used for production servers.')
        ->assertSee('Validate')
        ->assertSee('Delete');
});

test('cloud token detail page updates name and description', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Production Hetzner',
        'description' => 'Used for production servers.',
    ]);

    Livewire::test(Show::class, ['cloud_token_uuid' => $token->uuid])
        ->set('name', 'Renamed Hetzner')
        ->set('description', 'Used for staging servers.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->assertDatabaseHas('cloud_provider_tokens', [
        'id' => $token->id,
        'name' => 'Renamed Hetzner',
        'description' => 'Used for staging servers.',
    ]);
});

test('cloud token detail page validates the token', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers?per_page=1' => Http::response([], 200),
    ]);

    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
    ]);

    Livewire::test(Show::class, ['cloud_token_uuid' => $token->uuid])
        ->call('validateToken')
        ->assertDispatched('success');
});

test('cloud token detail page deletes unused tokens', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Production Hetzner',
    ]);

    Livewire::test(Show::class, ['cloud_token_uuid' => $token->uuid])
        ->call('delete')
        ->assertRedirectToRoute('security.cloud-tokens');

    $this->assertModelMissing($token);
});
