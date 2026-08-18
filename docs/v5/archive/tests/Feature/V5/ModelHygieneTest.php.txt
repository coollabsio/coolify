<?php

use App\Jobs\V5ReconcileServersJob;
use App\Jobs\V5ReconcileServerStateJob;
use App\Models\Application;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain;
use App\Models\V5\ContainerStatus;
use App\Models\V5\ResourceConnection;
use App\Models\V5\ResourceConnectionRule;
use App\Models\V5\Server as V5Server;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    dropModelHygieneTables();
    createModelHygieneTables();
});

afterEach(function () {
    dropModelHygieneTables();
});

it('registers the v5 application morph alias without enforcing a global morph map', function () {
    expect((new V5Application)->getMorphClass())->toBe('v5.application')
        ->and(Relation::getMorphedModel('v5.application'))->toBe(V5Application::class)
        // v4 models keep storing FQCNs — the map must not be enforced.
        ->and((new Application)->getMorphClass())->toBe(Application::class);
});

it('stores v5 resource connections with the morph alias and resolves it back', function () {
    $source = createModelHygieneApplication('api');
    $target = createModelHygieneApplication('postgres');

    $connection = ResourceConnection::query()->create([
        'team_id' => 1,
        'project_id' => 1,
        'environment_id' => 1,
        'resource_one_type' => $source->getMorphClass(),
        'resource_one_id' => $source->id,
        'resource_two_type' => $target->getMorphClass(),
        'resource_two_id' => $target->id,
        'resource_pair_key' => collect([
            $source->getMorphClass().':'.$source->getKey(),
            $target->getMorphClass().':'.$target->getKey(),
        ])->sort()->implode('|'),
        'created_by_user_id' => 1,
    ]);

    $storedRow = DB::table('v5_resource_connections')->where('id', $connection->id)->first();

    expect($storedRow->resource_one_type)->toBe('v5.application')
        ->and($storedRow->resource_two_type)->toBe('v5.application')
        ->and($storedRow->resource_pair_key)->toBe("v5.application:{$source->id}|v5.application:{$target->id}")
        ->and($connection->refresh()->resourceOne)->toBeInstanceOf(V5Application::class)
        ->and($connection->resourceOne->id)->toBe($source->id)
        ->and($connection->resourceTwo->id)->toBe($target->id);
});

it('rewrites legacy fqcn morph rows to the v5 application alias', function () {
    $fqcn = 'App\Models\V5\Application';

    DB::table('v5_resource_connections')->insert([
        'uuid' => 'legacy-connection',
        'team_id' => 1,
        'project_id' => 1,
        'environment_id' => 1,
        'resource_one_type' => $fqcn,
        'resource_one_id' => 1,
        'resource_two_type' => $fqcn,
        'resource_two_id' => 2,
        'resource_pair_key' => "{$fqcn}:1|{$fqcn}:2",
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('v5_resource_connection_rules')->insert([
        'connection_id' => 1,
        'source_resource_type' => $fqcn,
        'source_resource_id' => 1,
        'target_resource_type' => $fqcn,
        'target_resource_id' => 2,
        'protocol' => 'tcp',
        'port' => 5432,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = include database_path('migrations-v5/2026_07_06_090000_v5_convert_resource_connection_morphs_to_aliases.php');
    $migration->up();

    $connection = DB::table('v5_resource_connections')->where('uuid', 'legacy-connection')->first();
    $rule = DB::table('v5_resource_connection_rules')->first();

    expect($connection->resource_one_type)->toBe('v5.application')
        ->and($connection->resource_two_type)->toBe('v5.application')
        ->and($connection->resource_pair_key)->toBe('v5.application:1|v5.application:2')
        ->and($rule->source_resource_type)->toBe('v5.application')
        ->and($rule->target_resource_type)->toBe('v5.application');
});

it('maps the capabilities array onto the indexed booleans', function () {
    $server = new V5Server(['capabilities' => ['ingress']]);

    expect($server->is_ingress)->toBeTrue()
        ->and($server->has_coold)->toBeFalse()
        ->and($server->isIngress())->toBeTrue()
        ->and($server->hasCapability('ingress'))->toBeTrue()
        ->and($server->hasCapability('coold'))->toBeFalse()
        ->and($server->hasCapability('builder'))->toBeFalse()
        ->and($server->capabilities)->toBe(['ingress']);

    $server->has_coold = true;

    expect($server->capabilities)->toBe(['coold', 'ingress']);

    $server->capabilities = [];

    expect($server->has_coold)->toBeFalse()
        ->and($server->is_ingress)->toBeFalse()
        ->and($server->capabilities)->toBe([]);
});

it('persists capabilities writes into the boolean columns', function () {
    $server = createModelHygieneServer(['capabilities' => ['coold']]);

    $storedRow = DB::table('v5_servers')->where('id', $server->id)->first();

    expect((bool) $storedRow->has_coold)->toBeTrue()
        ->and((bool) $storedRow->is_ingress)->toBeFalse()
        ->and($server->fresh()->capabilities)->toBe(['coold']);
});

it('backfills the capability booleans from the legacy json column', function () {
    Schema::dropIfExists('v5_servers');
    Schema::create('v5_servers', function ($table) {
        $table->id();
        $table->string('uuid')->nullable()->unique();
        $table->string('name');
        $table->json('capabilities')->nullable();
        $table->timestamps();
    });

    DB::table('v5_servers')->insert([
        ['name' => 'full', 'capabilities' => json_encode(['coold', 'ingress'])],
        ['name' => 'ingress-only', 'capabilities' => json_encode(['ingress'])],
        ['name' => 'unknown-only', 'capabilities' => json_encode(['builder'])],
        ['name' => 'empty', 'capabilities' => null],
    ]);

    $migration = include database_path('migrations-v5/2026_07_06_090100_v5_convert_server_capabilities_to_booleans.php');
    $migration->up();

    $serversByName = DB::table('v5_servers')->get()->keyBy('name');

    expect(Schema::hasColumn('v5_servers', 'capabilities'))->toBeFalse()
        ->and((bool) $serversByName['full']->has_coold)->toBeTrue()
        ->and((bool) $serversByName['full']->is_ingress)->toBeTrue()
        ->and((bool) $serversByName['ingress-only']->has_coold)->toBeFalse()
        ->and((bool) $serversByName['ingress-only']->is_ingress)->toBeTrue()
        ->and((bool) $serversByName['unknown-only']->has_coold)->toBeFalse()
        ->and((bool) $serversByName['unknown-only']->is_ingress)->toBeFalse()
        ->and((bool) $serversByName['empty']->has_coold)->toBeFalse()
        ->and((bool) $serversByName['empty']->is_ingress)->toBeFalse();
});

it('selects reconcile-eligible servers through the indexed has_coold column', function () {
    Queue::fake();

    $eligible = createModelHygieneServer(['name' => 'coold-node', 'host' => '203.0.113.10', 'has_coold' => true]);
    createModelHygieneServer(['name' => 'plain-node', 'host' => '203.0.113.11', 'has_coold' => false]);

    DB::enableQueryLog();
    (new V5ReconcileServersJob)->handle();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($queries)->toContain('has_coold');
    Queue::assertPushed(V5ReconcileServerStateJob::class, 1);
    Queue::assertPushed(
        V5ReconcileServerStateJob::class,
        fn (V5ReconcileServerStateJob $job) => $job->serverId === $eligible->id,
    );
});

it('generates uuids only for models whose tables have a uuid column', function () {
    $server = createModelHygieneServer();

    expect($server->uuid)->not->toBeNull()
        ->and($server->getRouteKeyName())->toBe('uuid');

    // v5_container_statuses has no uuid column: the insert would fail if the
    // base model still tried to assign one.
    $containerStatus = ContainerStatus::query()->create([
        'team_id' => 1,
        'server_id' => $server->id,
        'container_id' => 'abc123',
        'status' => 'running',
    ]);

    expect($containerStatus->getAttribute('uuid'))->toBeNull()
        ->and($containerStatus->getRouteKeyName())->toBe('id')
        ->and((new ApplicationDomain)->getRouteKeyName())->toBe('id')
        ->and((new ResourceConnectionRule)->getRouteKeyName())->toBe('id');
});

it('keeps explicitly provided uuids', function () {
    $server = createModelHygieneServer(['uuid' => 'chosen-uuid']);

    expect($server->uuid)->toBe('chosen-uuid');
});

it('regenerates a colliding public id before inserting', function () {
    createModelHygieneServer(['uuid' => 'taken-uuid']);

    $colliding = new class extends V5Server
    {
        /** @var array<int, string> */
        public array $candidates = ['taken-uuid', 'fresh-uuid'];

        protected function newPublicIdCandidate(): string
        {
            return array_shift($this->candidates);
        }
    };

    $colliding->forceFill([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'collision-node',
        'host' => '203.0.113.99',
        'ssh_user' => 'root',
        'ssh_port' => 22,
    ])->save();

    expect($colliding->uuid)->toBe('fresh-uuid');
});

function dropModelHygieneTables(): void
{
    Schema::dropIfExists('v5_resource_connection_rules');
    Schema::dropIfExists('v5_resource_connections');
    Schema::dropIfExists('v5_application_domains');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_container_statuses');
    Schema::dropIfExists('v5_servers');
}

function createModelHygieneTables(): void
{
    V5TestSchema::createServersTable();
    V5TestSchema::createContainerStatusesTable();
    V5TestSchema::createApplicationsTable();
    V5TestSchema::createApplicationDomainsTable();
    V5TestSchema::createResourceConnectionsTable();
    V5TestSchema::createResourceConnectionRulesTable();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createModelHygieneServer(array $overrides = []): V5Server
{
    return V5Server::query()->create([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'edge-01',
        'host' => '203.0.113.'.random_int(2, 250),
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        ...$overrides,
    ]);
}

function createModelHygieneApplication(string $name): V5Application
{
    return V5Application::query()->create([
        'team_id' => 1,
        'project_id' => 1,
        'environment_id' => 1,
        'created_by_user_id' => 1,
        'name' => $name,
        'image' => "docker.io/library/{$name}:latest",
        'container_name' => "coolify-v5-{$name}",
    ]);
}
