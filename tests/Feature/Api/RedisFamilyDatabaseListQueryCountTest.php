<?php

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $user->createToken('test-token', ['root'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function createRedisFamilyDatabasesForQueryCount(int $environmentId, StandaloneDocker $destination, int $count): void
{
    foreach (range(1, $count) as $index) {
        $public = ['is_public' => true, 'public_port' => 30000 + $index];

        create_standalone_redis($environmentId, $destination, $public + ['image' => 'redis:7.2']);

        foreach (['create_standalone_keydb', 'create_standalone_dragonfly'] as $create) {
            $database = $create($environmentId, $destination, $public);
            EnvironmentVariable::create([
                'key' => 'REDIS_PASSWORD',
                'value' => "variable-password-{$index}",
                'resourceable_type' => $database->getMorphClass(),
                'resourceable_id' => $database->id,
            ]);
        }
    }
}

/**
 * Counts the queries of one database list request. The REDIS_USERNAME lookup of the StandaloneRedis
 * "retrieved" hook runs per model before any relation can be eager loaded, so it is not counted.
 * Pass $onlyTable to count only the queries that read that table.
 *
 * @return array{0: int, 1: array<int, array<string, mixed>>}
 */
function countRedisFamilyDatabaseListQueries(string $bearerToken, string $uri = '/api/v1/databases', ?string $onlyTable = null): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
        ->getJson($uri)
        ->assertOk();

    DB::disableQueryLog();

    $queries = collect(DB::getQueryLog())
        ->reject(fn (array $query) => str_contains($query['query'], 'audit_events') || in_array('REDIS_USERNAME', $query['bindings'], true))
        ->filter(fn (array $query) => $onlyTable === null || str_contains($query['query'], "from \"{$onlyTable}\""))
        ->count();

    return [$queries, $response->json()];
}

it('does not query environment variables per Redis, KeyDB and Dragonfly database in the database list', function () {
    createRedisFamilyDatabasesForQueryCount($this->environment->id, $this->destination, 1);
    // The first request also authenticates the token; measure warm requests only.
    countRedisFamilyDatabaseListQueries($this->bearerToken);
    [$queriesForOneEach] = countRedisFamilyDatabaseListQueries($this->bearerToken);

    createRedisFamilyDatabasesForQueryCount($this->environment->id, $this->destination, 4);
    [$queriesForFiveEach, $databases] = countRedisFamilyDatabaseListQueries($this->bearerToken);

    expect($databases)->toHaveCount(15)
        ->and($queriesForFiveEach)->toBe($queriesForOneEach);

    $modelsByUuid = collect([StandaloneRedis::class, StandaloneKeydb::class, StandaloneDragonfly::class])
        ->flatMap(fn (string $model) => $model::query()->get())
        ->keyBy('uuid');

    foreach ($databases as $database) {
        $model = $modelsByUuid[$database['uuid']];

        expect($database)->not->toHaveKey('runtime_environment_variables')
            ->and($database['internal_db_url'])->toBe($model->internal_db_url)
            ->and($database['external_db_url'])->toBe($model->external_db_url)
            ->and($database['internal_db_url'])->toStartWith('redis://')
            ->and($database['external_db_url'])->toStartWith('redis://');

        if (str_starts_with($database['name'], 'redis-database-')) {
            expect($database['internal_db_url'])->toStartWith('redis://default:'.rawurlencode($model->redis_password).'@');
        } else {
            expect($database['internal_db_url'])->toMatch('/^redis:\/\/:variable-password-\d@/');
        }
    }
});

it('does not query environment variables per Redis database in the environment details', function () {
    $uri = "/api/v1/projects/{$this->environment->project->uuid}/{$this->environment->uuid}";

    createRedisFamilyDatabasesForQueryCount($this->environment->id, $this->destination, 1);
    countRedisFamilyDatabaseListQueries($this->bearerToken, $uri);
    // The environment details lazy load each database's destination for external URLs; only variable reads are checked here.
    [$queriesForOne] = countRedisFamilyDatabaseListQueries($this->bearerToken, $uri, 'environment_variables');

    createRedisFamilyDatabasesForQueryCount($this->environment->id, $this->destination, 4);
    [$queriesForFive, $environment] = countRedisFamilyDatabaseListQueries($this->bearerToken, $uri, 'environment_variables');

    expect($environment['redis'])->toHaveCount(5)
        ->and($queriesForFive)->toBe($queriesForOne);

    foreach ($environment['redis'] as $database) {
        $model = StandaloneRedis::query()->where('uuid', $database['uuid'])->firstOrFail();

        expect($database)->not->toHaveKey('runtime_environment_variables')
            ->and($database['internal_db_url'])->toBe($model->internal_db_url)
            ->and($database['external_db_url'])->toBe($model->external_db_url);
    }
});

it('creates a missing Redis username variable only once when the variables are loaded', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination);
    $redis->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->delete();
    $redis->load('runtime_environment_variables');

    expect($redis->redis_username)->toBe('default')
        ->and($redis->redis_username)->toBe('default')
        ->and($redis->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->count())->toBe(1);
});
