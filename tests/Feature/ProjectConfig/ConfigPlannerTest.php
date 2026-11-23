<?php

use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    $this->ctx = ApplyContext::for($link->fresh());
});

function plannerApp(array $attributes = []): Application
{
    $fx = test()->fx;

    return Application::factory()->create(array_merge([
        'name' => 'api',
        'config_key' => 'api',
        'environment_id' => $fx['environment']->id,
        'destination_id' => $fx['destination']->id,
        'destination_type' => $fx['destination']->getMorphClass(),
        'git_repository' => 'acme/api',
        'git_branch' => 'main',
        'build_pack' => 'dockerfile',
        'ports_exposes' => '3000',
    ], $attributes));
}

function plannerResource(array $overrides = []): array
{
    return array_replace([
        'type' => 'application',
        'server' => 'prod-1',
        'source' => ['repository' => 'acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'],
        'ports' => ['exposes' => '3000'],
    ], $overrides);
}

function plannerPlan(array $resources, array $extra = [], array $environment = []): ConfigPlan
{
    $file = array_merge([
        'version' => 1,
        'resources' => $resources,
        'environments' => ['production' => array_merge(['branch' => 'main'], $environment)],
    ], $extra);
    $doc = app(ConfigParser::class)->parse(json_encode($file));
    $resolved = app(ConfigResolver::class)->forBranch($doc, 'main');

    return app(ConfigPlanner::class)->plan($resolved, test()->ctx);
}

function plannerKinds(ConfigPlan $plan): array
{
    return array_map(fn ($change) => $change->kind->value.':'.$change->key.($change->path === '' ? '' : ':'.$change->path), $plan->changes);
}

it('plans a create for a new key', function () {
    $plan = plannerPlan(['api' => plannerResource()]);

    expect(plannerKinds($plan))->toBe(['create:api'])
        ->and($plan->isDestructive())->toBeFalse()
        ->and($plan->errors)->toBe([]);
});

it('plans nothing when the stored state equals the file', function () {
    plannerApp();

    expect(plannerPlan(['api' => plannerResource()])->isEmpty())->toBeTrue();
});

it('plans a safe update for a changed field', function () {
    plannerApp();
    $plan = plannerPlan(['api' => plannerResource(['ports' => ['exposes' => '8080']])]);

    expect(plannerKinds($plan))->toBe(['update:api:ports.exposes'])
        ->and($plan->changes[0]->from)->toBe('3000')
        ->and($plan->changes[0]->to)->toBe('8080')
        ->and($plan->isDestructive())->toBeFalse();
});

it('treats a removed resource as destructive', function () {
    plannerApp();
    $plan = plannerPlan(['web' => plannerResource()]);

    expect(plannerKinds($plan))->toContain('remove:api', 'create:web')
        ->and($plan->isDestructive())->toBeTrue();
});

it('treats a removed variable as destructive and never shows values', function () {
    $app = plannerApp();
    ManagedResources::whileApplying(fn () => EnvironmentVariable::create(['key' => 'TOKEN', 'value' => 'super-secret-token', 'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id, 'config_meta' => ['form' => 'value']]));
    $plan = plannerPlan(['api' => plannerResource()]);

    expect(plannerKinds($plan))->toBe(['update:api:env.TOKEN'])
        ->and($plan->changes[0]->secret)->toBeTrue()
        ->and($plan->isDestructive())->toBeTrue()
        ->and(json_encode($plan->toArray()))->not->toContain('super-secret-token');
});

it('treats a server change as destructive', function () {
    plannerApp();
    Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $plan = plannerPlan(['api' => plannerResource(['server' => 'prod-2'])]);

    expect(plannerKinds($plan))->toBe(['update:api:server'])
        ->and($plan->isDestructive())->toBeTrue();
});

it('renames through moved without a create or a remove', function () {
    plannerApp(['config_key' => 'web']);
    $plan = plannerPlan(['api' => plannerResource()], ['moved' => [['from' => 'web', 'to' => 'api']]]);

    expect(plannerKinds($plan))->toBe(['rename:api'])
        ->and($plan->changes[0]->from)->toBe('web')
        ->and($plan->isDestructive())->toBeFalse();
});

it('fails a rename when both keys exist', function () {
    plannerApp(['config_key' => 'web']);
    plannerApp(['config_key' => 'api', 'name' => 'api2']);

    expect(array_map(fn ($e) => $e->path, plannerPlan(['api' => plannerResource()], ['moved' => [['from' => 'web', 'to' => 'api']]])->errors))->toContain('moved.0');
});

it('stops managing a resource through unmanage', function () {
    plannerApp(['config_key' => 'old']);
    $plan = plannerPlan(['api' => plannerResource()], ['unmanage' => ['old']]);

    expect(plannerKinds($plan))->toBe(['unmanage:old', 'create:api'])
        ->and($plan->isDestructive())->toBeFalse();
});

it('adopts an unkeyed resource through import', function () {
    $app = plannerApp(['config_key' => null]);
    $plan = plannerPlan(['api' => plannerResource(['ports' => ['exposes' => '8080']])], [], ['import' => [['to' => 'api', 'uuid' => $app->uuid]]]);

    expect(plannerKinds($plan))->toBe(['import:api', 'update:api:ports.exposes'])
        ->and($plan->warnings)->toBe([]);
});

it('rejects an import of an unknown, keyed, or other-type resource', function () {
    $keyed = plannerApp(['config_key' => 'other', 'name' => 'other']);
    $errors = fn (string $uuid, string $type = 'application') => array_map(fn ($e) => $e->path, plannerPlan(
        ['api' => $type === 'application' ? plannerResource() : ['type' => $type, 'server' => 'prod-1']],
        [],
        ['import' => [['to' => 'api', 'uuid' => $uuid]]],
    )->errors);

    expect($errors('nope'))->toContain('environments.production.import.0.uuid')
        ->and($errors($keyed->uuid))->toContain('environments.production.import.0.uuid');
});

it('treats a file without resources as destructive', function () {
    plannerApp();
    $plan = plannerPlan([]);

    expect($plan->removesAll)->toBeTrue()
        ->and($plan->isDestructive())->toBeTrue();
});

it('warns about unmanaged resources', function () {
    plannerApp(['config_key' => null, 'name' => 'handmade']);

    expect(plannerPlan(['api' => plannerResource()])->warnings)->toBe(['"handmade" (application) is not managed by coolify.json.']);
});

it('requires a server name that matches exactly one server', function () {
    Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'twin']);
    Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'twin']);

    $paths = fn (string $server) => array_map(fn ($e) => $e->path, plannerPlan(['api' => plannerResource(['server' => $server])])->errors);

    expect($paths('missing'))->toBe(['resources.api.server'])
        ->and($paths('twin'))->toBe(['resources.api.server'])
        ->and($paths('PROD-1'))->toBe(['resources.api.server']);
});

it('does not see servers of another team', function () {
    $other = projectConfigFixture();
    $other['server']->update(['name' => 'elsewhere']);

    expect(array_map(fn ($e) => $e->path, plannerPlan(['api' => plannerResource(['server' => 'elsewhere'])])->errors))->toBe(['resources.api.server']);
});

it('rejects a transferred server', function () {
    $this->fx['server']->update(['server_metadata' => ['transfer' => ['status' => 'transferred']]]);

    expect(array_map(fn ($e) => $e->path, plannerPlan(['api' => plannerResource()])->errors))->toBe(['resources.api.server']);
});

it('reports unknown resource keys with their path', function () {
    $plan = plannerPlan(['api' => plannerResource(['build' => ['pack' => 'dockerfile', 'typo' => 1], 'colour' => 'red'])]);

    expect(array_map(fn ($e) => $e->path, $plan->errors))->toContain('resources.api.build.typo', 'resources.api.colour');
});

it('reports a resource type without an adapter', function () {
    expect(array_map(fn ($e) => $e->path, plannerPlan(['cache' => ['type' => 'keydb', 'server' => 'prod-1']])->errors))->toBe(['resources.cache.type']);
})->skip(fn () => app(AdapterRegistry::class)->has('keydb'), 'keydb has an adapter');

it('lists required variables without a value', function () {
    $app = plannerApp();
    ManagedResources::whileApplying(fn () => EnvironmentVariable::create(['key' => 'SET', 'value' => 'x', 'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id, 'config_meta' => ['form' => 'required']]));
    $plan = plannerPlan(['api' => plannerResource(['env' => ['SET' => ['required' => true], 'MISSING' => ['required' => true]]]), 'new' => plannerResource(['env' => ['KEY' => ['required' => true]]])]);

    expect($plan->missingRequired)->toBe(['api' => ['MISSING'], 'new' => ['KEY']])
        ->and($plan->errors)->toBe([]);
});

it('changes the state hash after an edit in the GUI', function () {
    $app = plannerApp();
    $before = plannerPlan(['api' => plannerResource()])->stateHash;
    $app->update(['ports_exposes' => '9000']);

    expect(plannerPlan(['api' => plannerResource()])->stateHash)->not->toBe($before);
});
