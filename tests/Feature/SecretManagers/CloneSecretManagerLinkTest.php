<?php

use App\Livewire\Project\CloneMe;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->application->settings->fill(['is_container_label_readonly_enabled' => false])->save();

    $this->token = IntegrationToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'vault',
        'capabilities' => ['secrets'],
        'metadata' => ['base_url' => 'https://vault.example.com'],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function cloneLinkTestDatabase(Environment $environment, $destination): StandalonePostgresql
{
    return StandalonePostgresql::create([
        'name' => 'pg-linked',
        'uuid' => new_public_id(),
        'postgres_password' => 'secret',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'exited',
    ]);
}

test('cloning an application copies its secret manager link', function () {
    $this->application->secretManagerLink()->create([
        'integration_token_id' => $this->token->id,
        'settings' => ['mount' => 'secret', 'path' => 'apps/demo'],
    ]);

    $clone = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $link = $clone->secretManagerLink()->first();
    expect($link)->not->toBeNull()
        ->and($link->integration_token_id)->toBe($this->token->id)
        ->and($link->settings)->toBe(['mount' => 'secret', 'path' => 'apps/demo'])
        ->and($this->application->secretManagerLink()->exists())->toBeTrue()
        ->and(SecretManagerLink::count())->toBe(2);
});

test('cloning a database copies its secret manager link', function () {
    $database = cloneLinkTestDatabase($this->environment, $this->destination);
    $database->secretManagerLink()->create([
        'integration_token_id' => $this->token->id,
        'settings' => ['mount' => 'secret', 'path' => 'db'],
    ]);

    Livewire::test(ResourceOperations::class, ['resource' => $database])
        ->call('cloneTo', $this->destination->uuid)
        ->assertRedirect();

    $clone = StandalonePostgresql::whereKeyNot($database->id)->sole();
    expect($clone->secretManagerLink()->first()?->integration_token_id)->toBe($this->token->id)
        ->and($clone->secretManagerLink()->first()?->settings)->toBe(['mount' => 'secret', 'path' => 'db']);
});

test('cloning an environment copies the secret manager links of its resources', function () {
    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $database = cloneLinkTestDatabase($this->environment, $this->destination);
    $database->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(CloneMe::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('selectedDestination', $this->destination->uuid)
        ->set('newName', 'linked-clone')
        ->call('clone', 'environment')
        ->assertNotDispatched('error');

    $clonedEnvironment = $this->project->environments()->where('name', 'linked-clone')->sole();
    $clonedApplication = $clonedEnvironment->applications()->sole();
    $clonedDatabase = StandalonePostgresql::where('environment_id', $clonedEnvironment->id)->sole();

    expect($clonedApplication->secretManagerLink()->first()?->integration_token_id)->toBe($this->token->id)
        ->and($clonedDatabase->secretManagerLink()->first()?->integration_token_id)->toBe($this->token->id);
});

test('cloning does not copy a secret manager link whose token belongs to another team', function () {
    $foreignToken = IntegrationToken::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'provider' => 'vault',
        'capabilities' => ['secrets'],
    ]);
    $this->application->secretManagerLink()->create(['integration_token_id' => $foreignToken->id]);

    $clone = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    expect($clone->secretManagerLink()->exists())->toBeFalse();
});
