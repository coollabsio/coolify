<?php

use App\Livewire\Project\Shared\SecretManagerLinks;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutDefer();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $vaultToken = fn (string $name) => IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vault',
        'name' => $name,
        'token' => 'hvs.token',
        'capabilities' => ['secrets'],
        'metadata' => ['base_url' => 'https://vault.internal:8200'],
    ]);
    $this->currentToken = $vaultToken('Vault current');
    $this->nextToken = $vaultToken('Vault next');

    $this->application->secretManagerLink()->create([
        'integration_token_id' => $this->currentToken->id,
        'settings' => ['mount' => 'secret', 'path' => 'my-app/production'],
    ]);
});

test('switching to a token of the same provider rejects invalid settings and keeps the saved source', function () {
    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set([
            'settings' => ['mount' => ['nested' => 'value'], 'path' => 'my-app/production'],
            'integration_token_uuid' => $this->nextToken->uuid,
        ])
        ->assertHasErrors(['settings.mount']);

    $link = $this->application->secretManagerLink()->firstOrFail();
    expect($link->integration_token_id)->toBe($this->currentToken->id)
        ->and($link->settings)->toBe(['mount' => 'secret', 'path' => 'my-app/production']);
});

test('switching to a token of the same provider saves only the provider settings fields', function () {
    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set([
            'settings' => ['mount' => 'kv', 'path' => 'my-app/staging', 'unexpected' => 'value'],
            'integration_token_uuid' => $this->nextToken->uuid,
        ])
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $link = $this->application->secretManagerLink()->firstOrFail();
    expect($link->integration_token_id)->toBe($this->nextToken->id)
        ->and($link->settings)->toBe(['mount' => 'kv', 'path' => 'my-app/staging']);
});

test('switching to a token of the same provider keeps working while the settings are still empty', function () {
    $this->application->secretManagerLink()->update(['settings' => null]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set('integration_token_uuid', $this->nextToken->uuid)
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $link = $this->application->secretManagerLink()->firstOrFail();
    expect($link->integration_token_id)->toBe($this->nextToken->id)
        ->and($link->settings)->toBeNull();
});
