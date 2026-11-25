<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

test('resolveSharedEnvironmentVariables resolves environment-scoped variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'DRAGONFLY_URL',
        'value' => 'redis://dragonfly:6379',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('{{environment.DRAGONFLY_URL}}', $this->application);
    expect($resolved)->toBe('redis://dragonfly:6379');
});

test('resolveSharedEnvironmentVariables resolves project-scoped variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'DB_HOST',
        'value' => 'postgres.internal',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('{{project.DB_HOST}}', $this->application);
    expect($resolved)->toBe('postgres.internal');
});

test('resolveSharedEnvironmentVariables resolves team-scoped variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'GLOBAL_API_KEY',
        'value' => 'sk-123456',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('{{team.GLOBAL_API_KEY}}', $this->application);
    expect($resolved)->toBe('sk-123456');
});

test('resolveSharedEnvironmentVariables resolves server-scoped variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'COMMON_PG_HOST',
        'value' => 'postgres.coolify',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('{{server.COMMON_PG_HOST}}', $this->application);
    expect($resolved)->toBe('postgres.coolify');
});

test('resolveSharedEnvironmentVariables returns original when no match found', function () {
    $resolved = resolveSharedEnvironmentVariables('{{environment.NONEXISTENT}}', $this->application);
    expect($resolved)->toBe('{{environment.NONEXISTENT}}');
});

test('resolveSharedEnvironmentVariables handles null and empty values', function () {
    expect(resolveSharedEnvironmentVariables(null, $this->application))->toBeNull();
    expect(resolveSharedEnvironmentVariables('', $this->application))->toBe('');
    expect(resolveSharedEnvironmentVariables('plain-value', $this->application))->toBe('plain-value');
});

test('resolveSharedEnvironmentVariables resolves multiple variables in one string', function () {
    SharedEnvironmentVariable::create([
        'key' => 'HOST',
        'value' => 'myhost',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'PORT',
        'value' => '6379',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('redis://{{environment.HOST}}:{{environment.PORT}}', $this->application);
    expect($resolved)->toBe('redis://myhost:6379');
});

test('resolveSharedEnvironmentVariables handles spaces in pattern', function () {
    SharedEnvironmentVariable::create([
        'key' => 'MY_VAR',
        'value' => 'resolved-value',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);

    $resolved = resolveSharedEnvironmentVariables('{{ environment.MY_VAR }}', $this->application);
    expect($resolved)->toBe('resolved-value');
});

test('EnvironmentVariable real_value still resolves shared variables after refactor', function () {
    SharedEnvironmentVariable::create([
        'key' => 'DRAGONFLY_URL',
        'value' => 'redis://dragonfly:6379',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);

    $env = EnvironmentVariable::create([
        'key' => 'REDIS_URL',
        'value' => '{{environment.DRAGONFLY_URL}}',
        'resourceable_id' => $this->application->id,
        'resourceable_type' => $this->application->getMorphClass(),
    ]);

    expect($env->real_value)->toBe('redis://dragonfly:6379');
});

test('shared variable resolvers do not resolve references with invisible characters', function (string $reference) {
    SharedEnvironmentVariable::create([
        'key' => 'GLOBAL_API_KEY',
        'value' => 'sk-123456',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    $env = EnvironmentVariable::create([
        'key' => 'LEAK',
        'value' => $reference,
        'resourceable_id' => $this->application->id,
        'resourceable_type' => $this->application->getMorphClass(),
    ]);

    expect(resolveSharedEnvironmentVariables($reference, $this->application))->not->toContain('sk-123456');
    expect($env->real_value)->not->toContain('sk-123456');
})->with([
    "{{\u{200B}team.GLOBAL_API_KEY}}",
    "{{\u{FEFF}team.GLOBAL_API_KEY}}",
    "{{\u{200E}team.GLOBAL_API_KEY}}",
    "{{\u{00A0}team.GLOBAL_API_KEY}}",
]);

test('the compose resolver does not expand a reference with a NUL character', function () {
    SharedEnvironmentVariable::create(['key' => 'GLOBAL_API_KEY', 'value' => 'sk-123456', 'type' => 'team', 'team_id' => $this->team->id]);

    expect(resolveSharedEnvironmentVariables("{{\0team.GLOBAL_API_KEY}}", $this->application))->not->toContain('sk-123456');
});

test('environment and shared variable values cannot contain a NUL character', function () {
    expect(fn () => EnvironmentVariable::create([
        'key' => 'WITH_NUL',
        'value' => "a\0b",
        'resourceable_id' => $this->application->id,
        'resourceable_type' => $this->application->getMorphClass(),
    ]))->toThrow(ValidationException::class, 'NUL');

    expect(fn () => SharedEnvironmentVariable::create(['key' => 'WITH_NUL', 'value' => "a\0b", 'type' => 'team', 'team_id' => $this->team->id]))
        ->toThrow(ValidationException::class, 'NUL');

    expect(EnvironmentVariable::where('key', 'WITH_NUL')->exists())->toBeFalse();
    expect(SharedEnvironmentVariable::where('key', 'WITH_NUL')->exists())->toBeFalse();
});
