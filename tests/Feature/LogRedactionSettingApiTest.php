<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $this->database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-database',
        'postgres_password' => 'database-password',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

/**
 * @param  list<string>  $abilities
 */
function logRedactionApiToken(Team $team, string $role, array $abilities = ['write']): string
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function logRedactionSettingOf(string $resource): bool
{
    return match ($resource) {
        'application' => test()->application->settings->fresh()->redact_all_env_values_in_logs,
        'service' => test()->service->fresh()->redact_all_env_values_in_logs,
        'database' => test()->database->fresh()->redact_all_env_values_in_logs,
    };
}

function logRedactionPath(string $resource): string
{
    return match ($resource) {
        'application' => '/api/v1/applications/'.test()->application->uuid,
        'service' => '/api/v1/services/'.test()->service->uuid,
        'database' => '/api/v1/databases/'.test()->database->uuid,
    };
}

it('lets a team admin limit log redaction to locked values', function (string $resource) {
    $this->withToken(logRedactionApiToken($this->team, 'admin'))
        ->patchJson(logRedactionPath($resource), ['redact_all_env_values_in_logs' => false])
        ->assertOk();

    expect(logRedactionSettingOf($resource))->toBeFalse();
})->with(['application', 'service', 'database']);

it('rejects a non-boolean log redaction value', function (string $resource) {
    $this->withToken(logRedactionApiToken($this->team, 'admin'))
        ->patchJson(logRedactionPath($resource), ['redact_all_env_values_in_logs' => 'nope'])
        ->assertUnprocessable();

    expect(logRedactionSettingOf($resource))->toBeTrue();
})->with(['application', 'service', 'database']);

it('forbids a team member from changing log redaction', function (string $resource) {
    $this->withToken(logRedactionApiToken($this->team, 'member'))
        ->patchJson(logRedactionPath($resource), ['redact_all_env_values_in_logs' => false])
        ->assertForbidden();

    expect(logRedactionSettingOf($resource))->toBeTrue();
})->with(['application', 'service', 'database']);

it('forbids a read-only token from changing log redaction', function (string $resource) {
    $this->withToken(logRedactionApiToken($this->team, 'owner', ['read']))
        ->patchJson(logRedactionPath($resource), ['redact_all_env_values_in_logs' => false])
        ->assertForbidden();

    expect(logRedactionSettingOf($resource))->toBeTrue();
})->with(['application', 'service', 'database']);

it('does not let an admin of another team change log redaction', function (string $resource) {
    $this->withToken(logRedactionApiToken(Team::factory()->create(), 'owner'))
        ->patchJson(logRedactionPath($resource), ['redact_all_env_values_in_logs' => false])
        ->assertNotFound();

    expect(logRedactionSettingOf($resource))->toBeTrue();
})->with(['application', 'service', 'database']);
