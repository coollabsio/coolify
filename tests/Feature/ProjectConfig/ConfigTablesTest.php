<?php

use App\Enums\ConfigRevisionStatus;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\StandalonePostgresql;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
});

function configTablesApp(Environment $environment, string $key): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => test()->fx['destination']->id,
        'destination_type' => test()->fx['destination']->getMorphClass(),
        'config_key' => $key,
    ]);
}

it('rejects a second resource with the same config_key in one environment', function () {
    configTablesApp($this->fx['environment'], 'api');

    expect(fn () => configTablesApp($this->fx['environment'], 'api'))->toThrow(QueryException::class);
});

it('allows the same config_key in two environments', function () {
    $staging = Environment::factory()->create(['project_id' => $this->fx['project']->id, 'name' => 'staging']);

    configTablesApp($this->fx['environment'], 'api');
    configTablesApp($staging, 'api');

    expect(Application::where('config_key', 'api')->count())->toBe(2);
});

it('has a config_key column on every standalone database table', function () {
    $tables = ['standalone_postgresqls', 'standalone_mysqls', 'standalone_mariadbs', 'standalone_mongodbs', 'standalone_redis',
        'standalone_keydbs', 'standalone_dragonflies', 'standalone_clickhouses', 'standalone_sqlites', 'services', 'applications'];

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'config_key'))->toBeTrue("{$table} has no config_key");
    }
});

it('stores the link webhook secret encrypted and hides it', function () {
    $link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id,
        'webhook_secret' => 's3cret',
    ]);

    expect($link->getRawOriginal('webhook_secret'))->not->toBe('s3cret')
        ->and($link->fresh()->webhook_secret)->toBe('s3cret')
        ->and($link->toArray())->not->toHaveKey('webhook_secret')
        ->and($this->fx['environment']->fresh()->configLink->id)->toBe($link->id)
        ->and($link->teamId())->toBe($this->fx['team']->id);
});

it('allows one link per environment', function () {
    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);

    expect(fn () => EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]))
        ->toThrow(QueryException::class);
});

it('reports a suspended link', function () {
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    expect($link->isSuspended())->toBeFalse();

    $link->update(['suspended_at' => now()]);
    expect($link->isSuspended())->toBeTrue();
});

it('casts revision json columns and status', function () {
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    $revision = EnvironmentConfigRevision::factory()->create([
        'link_id' => $link->id,
        'plan' => ['changes' => []],
        'held_deployments' => [['application_uuid' => 'a1', 'commit_sha' => 'abc']],
        'status' => ConfigRevisionStatus::WaitingApproval,
    ])->fresh();

    expect($revision->plan)->toBe(['changes' => []])
        ->and($revision->held_deployments[0]['commit_sha'])->toBe('abc')
        ->and($revision->status)->toBe(ConfigRevisionStatus::WaitingApproval)
        ->and($revision->link->is($link))->toBeTrue();
});

it('casts config_meta on environment variables', function () {
    $app = configTablesApp($this->fx['environment'], 'api');
    $variable = EnvironmentVariable::create([
        'key' => 'STRIPE_KEY',
        'value' => '',
        'resourceable_type' => $app->getMorphClass(),
        'resourceable_id' => $app->id,
        'config_meta' => ['form' => 'required'],
    ]);

    expect($variable->fresh()->config_meta)->toBe(['form' => 'required']);
});

it('keeps database config_key unique per environment', function () {
    $make = fn () => StandalonePostgresql::forceCreate([
        'name' => 'db', 'postgres_password' => 'x', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
        'config_key' => 'db',
    ]);
    $make();

    expect($make)->toThrow(QueryException::class);
});
