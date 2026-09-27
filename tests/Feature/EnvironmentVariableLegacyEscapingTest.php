<?php

use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneKeydb;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        InstanceSettings::forceCreate(['id' => 0]);
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->resourceAttributes = [
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ];

    $this->service = Service::factory()->create([...$this->resourceAttributes, 'server_id' => $server->id]);
});

function legacyVariable(Model $resource, array $attributes): EnvironmentVariable
{
    $variable = $resource->environment_variables()->create($attributes);
    $variable->uses_legacy_escaping = true;
    $variable->save();

    return $variable->fresh();
}

test('the migration marks existing variables as legacy and new variables as exact', function () {
    $migration = require database_path('migrations/2026_09_27_154836_add_uses_legacy_escaping_to_environment_variables_table.php');
    $migration->down();

    $existing = $this->service->environment_variables()->create(['key' => 'EXISTING', 'value' => "a'b"]);

    $migration->up();

    expect($existing->fresh()->uses_legacy_escaping)->toBeTrue()
        ->and($this->service->environment_variables()->create(['key' => 'NEW', 'value' => "a'b"])->fresh()->uses_legacy_escaping)->toBeFalse();
});

test('changing the value or format of a legacy variable switches it to exact escaping', function (array $change) {
    $variable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);

    $variable->update($change);

    expect($variable->fresh()->uses_legacy_escaping)->toBeFalse();
})->with([
    'value' => [['value' => "a'c"]],
    'literal' => [['is_literal' => true]],
    'multiline' => [['is_multiline' => true]],
]);

test('saving a legacy variable without a value or format change keeps legacy escaping', function () {
    $variable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);

    $variable->update(['value' => "a'b", 'comment' => 'note']);

    expect($variable->fresh()->uses_legacy_escaping)->toBeTrue();
});

test('legacy service variables keep the old env file format', function () {
    $plain = legacyVariable($this->service, ['key' => 'PLAIN', 'value' => 'a\'b"c\\d']);
    $literal = legacyVariable($this->service, ['key' => 'LITERAL', 'value' => 'x$y', 'is_literal' => true]);
    $empty = legacyVariable($this->service, ['key' => 'EMPTY', 'value' => null]);

    expect($this->service->composeEnvironmentFileLine($plain))->toBe('PLAIN=a\\\'b\\"c\\\\d')
        ->and($this->service->composeEnvironmentFileLine($literal))->toBe("LITERAL='x\$y'")
        ->and($this->service->composeEnvironmentFileLine($empty))->toBe('EMPTY=');
});

test('legacy database variables keep the old compose environment format', function () {
    $database = StandalonePostgresql::forceCreate([
        'uuid' => 'postgres-legacy-test',
        'name' => 'Postgres legacy test',
        'image' => 'postgres:16-alpine',
        'postgres_password' => 'password',
        ...$this->resourceAttributes,
    ]);
    legacyVariable($database, ['key' => 'PLAIN', 'value' => "a'b"]);
    legacyVariable($database, ['key' => 'LITERAL', 'value' => 'p4ssword', 'is_literal' => true]);

    $action = new StartPostgresql;
    $action->database = $database->fresh();
    $environment = (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    expect($environment)->toContain("PLAIN=a\\'b")->toContain("LITERAL='p4ssword'");
});

test('legacy redis password variables keep the old start command', function () {
    $redis = StandaloneRedis::forceCreate([
        'uuid' => 'redis-legacy-test',
        'name' => 'Redis legacy test',
        'image' => 'redis:7-alpine',
        ...$this->resourceAttributes,
    ]);
    legacyVariable($redis, ['key' => 'REDIS_PASSWORD', 'value' => 'p4$$word']);

    $action = new StartRedis;
    $action->database = $redis->fresh();
    (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    expect((new ReflectionMethod($action, 'buildStartCommand'))->invoke($action))
        ->toBe('redis-server --requirepass p4$$word --appendonly yes');
});

test('keydb column passwords keep the old start command', function () {
    $keydb = StandaloneKeydb::forceCreate([
        'uuid' => 'keydb-legacy-test',
        'name' => 'KeyDB legacy test',
        'image' => 'eqalpha/keydb:latest',
        'keydb_password' => 'p4$$word',
        ...$this->resourceAttributes,
    ]);

    $action = new StartKeydb;
    $action->database = $keydb;
    (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    expect((new ReflectionMethod($action, 'buildStartCommand'))->invoke($action))
        ->toBe("keydb-server --requirepass 'p4\$\$word' --appendonly yes");
});

test('the update button switches a legacy variable to exact escaping', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $variable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);

    Livewire::test(Show::class, ['env' => $variable, 'type' => 'service'])
        ->call('loadValues')
        ->call('submit')
        ->assertHasNoErrors();

    expect($variable->fresh()->uses_legacy_escaping)->toBeFalse();
});

test('instant saves keep legacy escaping', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $variable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);

    Livewire::test(Show::class, ['env' => $variable, 'type' => 'service'])
        ->call('loadValues')
        ->call('instantSave');

    expect($variable->fresh()->uses_legacy_escaping)->toBeTrue();
});

test('the editor does not tell users about legacy escaping', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $variable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);

    Livewire::test(Show::class, ['env' => $variable, 'type' => 'service'])
        ->call('loadValues')
        ->assertDontSee('escaping');
});

test('api updates switch service and database variables to exact escaping', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $serviceVariable = legacyVariable($this->service, ['key' => 'CSP', 'value' => "a'b"]);
    $bulkVariable = legacyVariable($this->service, ['key' => 'BULK', 'value' => "a'b"]);
    $database = StandalonePostgresql::forceCreate([
        'uuid' => 'postgres-api-legacy-test',
        'name' => 'Postgres api legacy test',
        'image' => 'postgres:16-alpine',
        'postgres_password' => 'password',
        ...$this->resourceAttributes,
    ]);
    $databaseVariable = legacyVariable($database, ['key' => 'CSP', 'value' => "a'b"]);
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->patchJson("/api/v1/services/{$this->service->uuid}/envs", ['key' => 'CSP', 'value' => "a'b"])->assertStatus(201);
    $this->withHeaders($headers)->patchJson("/api/v1/services/{$this->service->uuid}/envs/bulk", ['data' => [['key' => 'BULK', 'value' => "a'b"]]])->assertStatus(201);
    $this->withHeaders($headers)->patchJson("/api/v1/databases/{$database->uuid}/envs", ['key' => 'CSP', 'value' => "a'b"])->assertStatus(201);

    expect($serviceVariable->fresh()->uses_legacy_escaping)->toBeFalse()
        ->and($bulkVariable->fresh()->uses_legacy_escaping)->toBeFalse()
        ->and($databaseVariable->fresh()->uses_legacy_escaping)->toBeFalse();
});

test('api updates switch application variables to exact escaping', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $application = Application::factory()->create($this->resourceAttributes);
    $singleVariable = legacyVariable($application, ['key' => 'CSP', 'value' => "a'b"]);
    $bulkVariable = legacyVariable($application, ['key' => 'BULK', 'value' => "a'b"]);
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->patchJson("/api/v1/applications/{$application->uuid}/envs", ['key' => 'CSP', 'value' => "a'b"])->assertStatus(201);
    $this->withHeaders($headers)->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", ['data' => [['key' => 'BULK', 'value' => "a'b"]]])->assertStatus(201);

    expect($singleVariable->fresh()->uses_legacy_escaping)->toBeFalse()
        ->and($bulkVariable->fresh()->uses_legacy_escaping)->toBeFalse();
});

test('the resolved value shows what the container gets for exact and legacy variables', function (array $attributes, string $exact, string $legacy) {
    $exactVariable = $this->service->environment_variables()->create(['key' => 'EXACT', ...$attributes]);
    $legacyVariable = legacyVariable($this->service, ['key' => 'LEGACY', ...$attributes]);

    expect($exactVariable->fresh()->real_value)->toBe($exact)
        ->and($legacyVariable->real_value)->toBe($legacy);
})->with([
    'plain with quotes' => [['value' => 'a\'b"c\\d'], 'a\'b"c\\d', 'a\\\'b\\"c\\\\d'],
    'literal' => [['value' => 'x$y', 'is_literal' => true], 'x$y', "'x\$y'"],
    'json' => [['value' => '{"a":"b"}'], '{"a":"b"}', '{"a":"b"}'],
]);

test('the resolved value of an exact shared reference has no escaping', function () {
    SharedEnvironmentVariable::query()->create([
        'key' => 'CSP',
        'value' => "array:'self'",
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);
    $variable = $this->service->environment_variables()->create(['key' => 'CSP', 'value' => '{{environment.CSP}}']);

    expect($variable->fresh()->real_value)->toBe("array:'self'");
});

test('log redaction covers the exact and the old escaped forms of a value', function () {
    $variable = $this->service->environment_variables()->create(['key' => 'SECRET', 'value' => "p4ss'word"]);

    expect($variable->fresh()->logRedactionValues())->toContain("p4ss'word")->toContain("p4ss\\'word");
});

test('the api shows the resolved value by escaping and never shows the legacy flag', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $this->service->environment_variables()->create(['key' => 'EXACT', 'value' => "a'b"]);
    legacyVariable($this->service, ['key' => 'LEGACY', 'value' => "a'b"]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson("/api/v1/services/{$this->service->uuid}/envs")
        ->assertOk();

    $variables = collect($response->json())->keyBy('key');
    expect($variables['EXACT']['real_value'])->toBe("a'b")
        ->and($variables['LEGACY']['real_value'])->toBe("a\\'b")
        ->and($variables['EXACT'])->not->toHaveKey('uses_legacy_escaping')
        ->and($variables['LEGACY'])->not->toHaveKey('uses_legacy_escaping');
});

test('the editor shows the resolved value by escaping', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    SharedEnvironmentVariable::query()->create([
        'key' => 'CSP',
        'value' => "array:'self'",
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'team_id' => $this->team->id,
    ]);
    $exact = $this->service->environment_variables()->create(['key' => 'EXACT', 'value' => '{{environment.CSP}}']);
    $legacy = legacyVariable($this->service, ['key' => 'LEGACY', 'value' => '{{environment.CSP}}']);

    Livewire::test(Show::class, ['env' => $exact->fresh(), 'type' => 'service'])
        ->call('loadValues')
        ->assertSet('real_value', "array:'self'");
    Livewire::test(Show::class, ['env' => $legacy, 'type' => 'service'])
        ->call('loadValues')
        ->assertSet('real_value', "array:\\'self\\'");
});
