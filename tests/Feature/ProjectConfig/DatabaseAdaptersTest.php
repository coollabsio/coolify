<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id])->fresh();
    $this->ctx = ApplyContext::for($this->link);
    $this->restarted = [];
    $restarted = &$this->restarted;
    app()->instance(HeldDeployments::class, new class($restarted) extends HeldDeployments
    {
        public function __construct(private array &$log) {}

        public function deploy(Application $application, ?string $commit): void {}

        public function restart(Model $resource, bool $created): void
        {
            $this->log[] = [$resource->config_key, $created];
        }
    });
});

function dbPlan(array $resources): ConfigPlan
{
    $file = json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);

    return app(ConfigPlanner::class)->plan(app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse($file), 'main'), test()->ctx);
}

function dbApply(array $resources): EnvironmentConfigRevision
{
    $file = json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
    $revision = app(ConfigRevisions::class)->record(test()->link, $file, 'api');
    app(ConfigApplier::class)->apply($revision);

    return $revision->fresh();
}

dataset('database types', ['postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'keydb', 'dragonfly', 'clickhouse', 'sqlite']);

it('creates each database type and plans nothing afterwards', function (string $type) {
    $desired = ['db' => ['type' => $type, 'server' => 'prod-1', 'limits' => ['memory' => '512m']]];

    $revision = dbApply($desired);

    expect($revision->status->value)->toBe('applied', (string) $revision->error);
    $model = app(AdapterRegistry::class)->for($type)->find($this->fx['environment'], 'db');
    expect($model)->not->toBeNull()
        ->and($model->name)->toBe('db')
        ->and($model->limits_memory)->toBe('512m')
        ->and($this->restarted)->toBe([['db', true]]);

    $plan = dbPlan($desired);
    expect($plan->errors)->toBe([])->and(array_map(fn ($c) => $c->path, $plan->changes))->toBe([]);
})->with('database types');

it('never reads passwords', function (string $type) {
    dbApply(['db' => ['type' => $type, 'server' => 'prod-1']]);
    $adapter = app(AdapterRegistry::class)->for($type);
    $model = $adapter->find($this->fx['environment'], 'db');
    $passwords = collect($model->getAttributes())->filter(fn ($value, $column) => str_contains($column, 'password'))->keys()
        ->map(fn ($column) => $model->{$column})->filter()->all();
    $json = json_encode($adapter->read($model, $this->ctx));
    expect($json)->not->toContain('_password');

    foreach ($passwords as $password) {
        expect($json)->not->toContain($password);
    }
})->with('database types');

it('keeps the default data volume when the file lists no storages', function () {
    dbApply(['db' => ['type' => 'postgresql', 'server' => 'prod-1']]);
    $db = StandalonePostgresql::where('config_key', 'db')->first();

    expect($db->persistentStorages()->where('name', 'postgres-data-'.$db->uuid)->exists())->toBeTrue()
        ->and(app(AdapterRegistry::class)->for('postgresql')->read($db, $this->ctx))->not->toHaveKey('storages');
});

it('classifies image changes', function (string $from, string $to, bool $destructive) {
    expect(app(AdapterRegistry::class)->for('postgresql')->destructiveFieldChange('image', $from, $to))->toBe($destructive);
})->with([
    'major' => ['postgres:16-alpine', 'postgres:17-alpine', true],
    'minor' => ['postgres:16.1', 'postgres:16.4', false],
    'latest' => ['postgres:latest', 'postgres:17', true],
    'repository' => ['postgres:16', 'timescale/timescaledb:16', true],
]);

it('treats a changed database user as destructive', function () {
    expect(app(AdapterRegistry::class)->for('postgresql')->destructiveFieldChange('credentials.user', 'postgres', 'app'))->toBeTrue();
});

it('resolves a database reference in an application variable', function () {
    $revision = dbApply([
        'db' => ['type' => 'postgresql', 'server' => 'prod-1'],
        'api' => [
            'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
            'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
            'env' => ['DATABASE_URL' => ['ref' => 'resource.db.internal_url']],
        ],
    ]);

    $db = StandalonePostgresql::where('config_key', 'db')->first();
    $api = Application::where('config_key', 'api')->first();
    expect($revision->status->value)->toBe('applied', (string) $revision->error)
        ->and(EnvironmentVariable::where('resourceable_id', $api->id)->where('resourceable_type', $api->getMorphClass())->where('key', 'DATABASE_URL')->where('is_preview', false)->value('value'))
        ->toBe($db->internal_db_url);
});

it('manages backups and rejects an S3 storage of another team', function () {
    $other = projectConfigFixture();
    session(['currentTeam' => $this->fx['team']]);
    S3Storage::forceCreate(['name' => 'foreign', 'team_id' => $other['team']->id, 'region' => 'x', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com']);
    S3Storage::forceCreate(['name' => 'backups', 'team_id' => $this->fx['team']->id, 'region' => 'x', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com']);

    expect(array_map(fn ($e) => $e->path, dbPlan(['db' => ['type' => 'postgresql', 'server' => 'prod-1', 'backups' => [['frequency' => 'daily', 's3_storage' => 'foreign']]]])->errors))
        ->toContain('resources.db.backups.0.s3_storage');

    $desired = ['db' => ['type' => 'postgresql', 'server' => 'prod-1', 'backups' => [['frequency' => 'daily', 's3_storage' => 'backups', 'retention_local_amount' => 7]]]];
    dbApply($desired);
    $db = StandalonePostgresql::where('config_key', 'db')->first();

    expect($db->scheduledBackups()->count())->toBe(1)
        ->and($db->scheduledBackups()->first()->team_id)->toBe($this->fx['team']->id)
        ->and(dbPlan($desired)->changes)->toBe([]);
});

it('rejects vault references on databases', function () {
    expect(array_map(fn ($e) => $e->path, dbPlan(['db' => ['type' => 'postgresql', 'server' => 'prod-1', 'env' => ['X' => ['ref' => 'vault.X']]]])->errors))
        ->toContain('resources.db.env.X');
});
