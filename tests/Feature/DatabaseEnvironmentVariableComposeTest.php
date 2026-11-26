<?php

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->firstOrCreate(['id' => 0]);

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @param  array<int, array{0: string, 1: ?string, 2?: bool, 3?: bool}>  $variables
 * @return array<int, string>
 */
function postgresComposeEnvironment(array $variables): array
{
    $database = StandalonePostgresql::forceCreate([
        'uuid' => 'postgres-env-test',
        'name' => 'Postgres env test',
        'image' => 'postgres:16-alpine',
        'postgres_password' => 'password',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);

    foreach ($variables as $variable) {
        $database->runtime_environment_variables()->create([
            'key' => $variable[0],
            'value' => $variable[1],
            'is_literal' => $variable[2] ?? false,
            'is_multiline' => $variable[3] ?? false,
        ]);
    }

    $action = new StartPostgresql;
    $action->database = $database->fresh();

    return (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);
}

test('literal database values are passed without added quotes and keep dollars literal', function () {
    $environment = postgresComposeEnvironment([
        ['CSP', "array:'self',blob:,https://*", true],
        ['SECRET', 'p4$$w"ord$X `id` C:\\path', true],
    ]);

    expect($environment)
        ->toContain("CSP=array:'self',blob:,https://*")
        ->toContain('SECRET=p4$$$$w"ord$$X `id` C:\\path');
});

test('plain database values are passed without backslash escaping', function () {
    $environment = postgresComposeEnvironment([
        ['CSP', "array:'self',blob:,https://*"],
        ['QUOTED', 'say "hi" C:\\path'],
    ]);

    expect($environment)
        ->toContain("CSP=array:'self',blob:,https://*")
        ->toContain('QUOTED=say "hi" C:\\path');
});

test('plain database values keep compose interpolation', function () {
    $environment = postgresComposeEnvironment([
        ['URL', 'postgres://$USER@db'],
    ]);

    expect($environment)->toContain('URL=postgres://$USER@db');
});

test('multiline and json database values keep dollars literal', function () {
    $environment = postgresComposeEnvironment([
        ['CERT', "line 'one'\nline \$two", false, true],
        ['CONFIG', '{"price":"$5"}'],
    ]);

    expect($environment)
        ->toContain("CERT=line 'one'\nline \$\$two")
        ->toContain('CONFIG={"price":"$$5"}');
});

test('empty database values stay empty', function () {
    expect(postgresComposeEnvironment([['EMPTY', null]]))->toContain('EMPTY=');
});

test('mariadb literal values are passed without added quotes', function () {
    $database = StandaloneMariadb::forceCreate([
        'uuid' => 'mariadb-env-test',
        'name' => 'MariaDB env test',
        'image' => 'mariadb:11',
        'mariadb_root_password' => 'password',
        'mariadb_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $database->runtime_environment_variables()->create([
        'key' => 'CSP',
        'value' => "array:'self' \$HOME",
        'is_literal' => true,
    ]);

    $action = new StartMariadb;
    $action->database = $database->fresh();
    $environment = (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    expect($environment)->toContain("CSP=array:'self' \$\$HOME");
});

/**
 * Compose interpolates `$` in healthchecks too, so the healthcheck must get the same value as the
 * container environment.
 */
function databaseHealthcheckCredential(object $action, object $database, array $variable, string $property): string
{
    $environmentVariable = $database->runtime_environment_variables()->create([
        'key' => $variable[0],
        'value' => $variable[1],
        'is_literal' => $variable[2] ?? false,
    ]);
    if ($variable[3] ?? false) {
        $environmentVariable->uses_legacy_escaping = true;
        $environmentVariable->save();
    }
    $action->database = $database->fresh();
    (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    return (new ReflectionProperty($action, $property))->getValue($action);
}

test('database healthcheck credentials match the container environment', function (string $databaseType, array $variable, string $property, string $expected) {
    [$modelClass, $actionClass, $attributes] = match ($databaseType) {
        'postgresql' => [StandalonePostgresql::class, StartPostgresql::class, ['image' => 'postgres:16-alpine', 'postgres_password' => 'password']],
        'mysql' => [StandaloneMysql::class, StartMysql::class, ['image' => 'mysql:8', 'mysql_root_password' => 'password', 'mysql_password' => 'password']],
        'clickhouse' => [StandaloneClickhouse::class, StartClickhouse::class, ['image' => 'clickhouse/clickhouse-server', 'clickhouse_admin_password' => 'password']],
    };
    $database = $modelClass::forceCreate([
        'uuid' => "{$databaseType}-healthcheck-test",
        'name' => "{$databaseType} healthcheck test",
        ...$attributes,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);

    expect(databaseHealthcheckCredential(new $actionClass, $database, $variable, $property))->toBe($expected);
})->with([
    'postgres literal user' => ['postgresql', ['POSTGRES_USER', 'us$er', true], 'resolvedPostgresUser', 'us$$er'],
    'postgres plain user' => ['postgresql', ['POSTGRES_USER', 'us$er'], 'resolvedPostgresUser', 'us$er'],
    'mysql literal root password' => ['mysql', ['MYSQL_ROOT_PASSWORD', 'p4$$w', true], 'resolvedMysqlRootPassword', 'p4$$$$w'],
    'mysql legacy literal root password' => ['mysql', ['MYSQL_ROOT_PASSWORD', 'p4$$w', true, true], 'resolvedMysqlRootPassword', 'p4$$w'],
    'clickhouse literal password' => ['clickhouse', ['CLICKHOUSE_PASSWORD', 'p4$w', true], 'resolvedClickhousePassword', 'p4$$w'],
    'clickhouse plain password' => ['clickhouse', ['CLICKHOUSE_PASSWORD', 'p4$w'], 'resolvedClickhousePassword', 'p4$w'],
]);
