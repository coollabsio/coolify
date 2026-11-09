<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\Service;
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

function serviceFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
}

function servicePlan(array $resources): ConfigPlan
{
    return app(ConfigPlanner::class)->plan(app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse(serviceFile($resources)), 'main'), test()->ctx);
}

function serviceApply(array $resources): EnvironmentConfigRevision
{
    $revision = app(ConfigRevisions::class)->record(test()->link, serviceFile($resources), 'api');
    app(ConfigApplier::class)->apply($revision);

    return $revision->fresh();
}

const SERVICE_TEST_COMPOSE = "services:\n  web:\n    image: 'nginx:alpine'\n    environment:\n      - SERVICE_URL_WEB_80\n      - 'APP_MODE=\${APP_MODE:-prod}'\n  worker:\n    image: 'busybox:latest'\n    command: 'sleep infinity'\n";

it('creates a template service and plans nothing afterwards', function () {
    $desired = ['kuma' => ['type' => 'service', 'server' => 'prod-1', 'template' => 'uptime-kuma']];

    $revision = serviceApply($desired);

    expect($revision->status->value)->toBe('applied', (string) $revision->error);
    $service = Service::where('config_key', 'kuma')->first();
    expect($service->service_type)->toBe('uptime-kuma')
        ->and($service->applications()->count())->toBe(1)
        ->and($this->restarted)->toBe([['kuma', true]]);

    $plan = servicePlan($desired);
    expect($plan->errors)->toBe([])->and(array_map(fn ($c) => $c->path, $plan->changes))->toBe([]);
});

it('creates a raw compose service with domains and plans nothing afterwards', function () {
    $desired = ['stack' => [
        'type' => 'service', 'server' => 'prod-1', 'compose' => SERVICE_TEST_COMPOSE,
        'services' => ['web' => ['domains' => ['https://stack.acme.com']]],
        'env' => ['APP_MODE' => ['value' => 'staging']],
    ]];

    $revision = serviceApply($desired);

    expect($revision->status->value)->toBe('applied', (string) $revision->error);
    $service = Service::where('config_key', 'stack')->first();
    expect($service->applications()->where('name', 'web')->value('fqdn'))->toBe('https://stack.acme.com')
        ->and(EnvironmentVariable::where('resourceable_id', $service->id)->where('resourceable_type', $service->getMorphClass())->where('key', 'APP_MODE')->value('value'))->toBe('staging');

    $plan = servicePlan($desired);
    expect($plan->errors)->toBe([])->and(array_map(fn ($c) => $c->path, $plan->changes))->toBe([]);
});

it('rejects a compose file with an injection payload', function () {
    $bad = "services:\n  web:\n    image: 'nginx:alpine'\n    volumes:\n      - '/tmp/\$(id):/data'\n";

    expect(array_map(fn ($e) => $e->path, servicePlan(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => $bad]])->errors))
        ->toContain('resources.stack.compose');
});

it('needs exactly one of template and compose, and a known template', function () {
    expect(array_map(fn ($e) => $e->path, servicePlan(['s' => ['type' => 'service', 'server' => 'prod-1']])->errors))->toContain('resources.s.template')
        ->and(array_map(fn ($e) => $e->path, servicePlan(['s' => ['type' => 'service', 'server' => 'prod-1', 'template' => 'uptime-kuma', 'compose' => SERVICE_TEST_COMPOSE]])->errors))->toContain('resources.s.template')
        ->and(array_map(fn ($e) => $e->path, servicePlan(['s' => ['type' => 'service', 'server' => 'prod-1', 'template' => 'no-such-template']])->errors))->toContain('resources.s.template');
});

it('keeps the generated domain of a template service until the file sets one', function () {
    serviceApply(['kuma' => ['type' => 'service', 'server' => 'prod-1', 'template' => 'uptime-kuma']]);
    $generated = Service::where('config_key', 'kuma')->first()->applications()->first()->fqdn;
    expect($generated)->not->toBeEmpty();

    serviceApply(['kuma' => ['type' => 'service', 'server' => 'prod-1', 'template' => 'uptime-kuma', 'services' => ['uptime-kuma' => ['domains' => ['https://status.acme.com']]]]]);

    expect(Service::where('config_key', 'kuma')->first()->applications()->first()->fqdn)->toBe('https://status.acme.com');
});

it('treats removing a compose service as destructive', function () {
    serviceApply(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => SERVICE_TEST_COMPOSE]]);
    $withoutWorker = "services:\n  web:\n    image: 'nginx:alpine'\n    environment:\n      - SERVICE_URL_WEB_80\n      - 'APP_MODE=\${APP_MODE:-prod}'\n";

    $plan = servicePlan(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => $withoutWorker]]);

    expect($plan->isDestructive())->toBeTrue();
});

it('stores a server reference on a service variable and rejects vault references', function () {
    serviceApply(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => SERVICE_TEST_COMPOSE, 'env' => ['REGION' => ['ref' => 'server.REGION']]]]);
    $service = Service::where('config_key', 'stack')->first();

    expect(EnvironmentVariable::where('resourceable_id', $service->id)->where('resourceable_type', $service->getMorphClass())->where('key', 'REGION')->value('value'))->toBe('{{server.REGION}}')
        ->and(array_map(fn ($e) => $e->path, servicePlan(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => SERVICE_TEST_COMPOSE, 'env' => ['X' => ['ref' => 'vault.X']]]])->errors))->toContain('resources.stack.env.X');
});

it('is registered and covered', function () {
    expect(app(AdapterRegistry::class)->has('service'))->toBeTrue();
});

it('rejects a resource reference to a service at plan time', function () {
    $plan = servicePlan([
        'stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => SERVICE_TEST_COMPOSE],
        'api' => ['type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
            'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'], 'env' => ['URL' => ['ref' => 'resource.stack.host']]],
    ]);

    expect(array_map(fn ($e) => $e->path, $plan->errors))->toContain('resources.api.env.URL');
});
