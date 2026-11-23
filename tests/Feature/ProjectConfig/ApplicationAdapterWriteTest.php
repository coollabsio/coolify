<?php

use App\Actions\Application\LookupGitAppRepository;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\StandaloneDocker;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    $this->ctx = ApplyContext::for($link->fresh(), $this->fx['user']);
    $this->adapter = app(AdapterRegistry::class)->for('application');
    $this->githubApp = GithubApp::forceCreate([
        'uuid' => 'gh1', 'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $this->fx['team']->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fx['team']->id])->id,
    ]);
    app()->instance(LookupGitAppRepository::class, new class extends LookupGitAppRepository
    {
        public function github(GithubApp $githubApp, string $gitRepository): array
        {
            return ['source' => $githubApp, 'repository' => $gitRepository, 'repository_project_id' => 4242];
        }
    });
});

function writeTestDesired(array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'application',
        'server' => 'prod-1',
        'source' => ['repository' => 'acme/api', 'branch' => 'main', 'github_app' => 'acme-app'],
        'build' => ['pack' => 'dockerfile'],
        'ports' => ['exposes' => '3000'],
        'domains' => ['HTTPS://Api.acme.com/'],
    ], $overrides);
}

function writeTestPlanIsEmpty(array $desired): bool
{
    $file = ['version' => 1, 'resources' => ['api' => $desired], 'environments' => ['production' => ['branch' => 'main']]];
    $resolved = app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse(json_encode($file)), 'main');
    $plan = app(ConfigPlanner::class)->plan($resolved, test()->ctx);

    return $plan->isEmpty() && $plan->errors === [];
}

it('creates an application that reads back as the desired state', function () {
    $desired = writeTestDesired(['health_check' => ['enabled' => true, 'path' => '/health'], 'settings' => ['is_auto_deploy_enabled' => false]]);
    $app = $this->adapter->create('api', $desired, $this->ctx);

    expect($app->config_key)->toBe('api')
        ->and($app->environment_id)->toBe($this->fx['environment']->id)
        ->and($app->destination_id)->toBe($this->fx['destination']->id)
        ->and($app->repository_project_id)->toBe(4242)
        ->and($this->adapter->read($app->fresh(), $this->ctx))->toEqual($this->adapter->canonical('api', $desired));
});

it('plans nothing after an apply, also with unnormalized domains', function () {
    $desired = writeTestDesired();
    $this->adapter->create('api', $desired, $this->ctx);

    expect(writeTestPlanIsEmpty($desired))->toBeTrue();
});

it('resets an omitted field to its default', function () {
    $app = $this->adapter->create('api', writeTestDesired(['health_check' => ['path' => '/health']]), $this->ctx);
    $this->adapter->update($app->fresh(), writeTestDesired(), $this->ctx);

    expect($app->fresh()->health_check_path)->toBe('/');
});

it('fails validation for a domain that another resource of the team uses', function () {
    Application::factory()->create([
        'name' => 'other', 'fqdn' => 'https://api.acme.com',
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);

    expect(array_map(fn ($e) => $e->path, $this->adapter->validate('api', writeTestDesired(), $this->ctx)))->toContain('resources.api.domains');
});

it('fails validation for a git source of another team', function () {
    $other = projectConfigFixture();
    $this->githubApp->update(['team_id' => $other['team']->id]);

    expect(array_map(fn ($e) => $e->path, $this->adapter->validate('api', writeTestDesired(), $this->ctx)))->toContain('resources.api.source.github_app');
});

it('fails validation for a GitLab app source', function () {
    $desired = writeTestDesired();
    unset($desired['source']['github_app']);
    $desired['source']['gitlab_app'] = 'gl';

    expect(array_map(fn ($e) => $e->path, $this->adapter->validate('api', $desired, $this->ctx)))->toContain('resources.api.source.gitlab_app');
});

it('fails validation for invalid values with their file path', function () {
    $errors = $this->adapter->validate('api', writeTestDesired(['build' => ['pack' => 'heroku'], 'health_check' => ['method' => 'PUT']]), $this->ctx);

    expect(array_map(fn ($e) => $e->path, $errors))->toContain('resources.api.build.pack', 'resources.api.health_check.method');
});

it('requires a destination when the server has more than one', function () {
    StandaloneDocker::forceCreate(['name' => 'second', 'network' => 'second-net', 'server_id' => $this->fx['server']->id, 'uuid' => 'sd2']);

    expect(array_map(fn ($e) => $e->path, $this->adapter->validate('api', writeTestDesired(), $this->ctx)))->toContain('resources.api.destination');

    $app = $this->adapter->create('api', writeTestDesired(['destination' => 'second-net']), $this->ctx);
    expect($app->destination->network)->toBe('second-net');
});

it('regenerates the stored labels after a domain change', function () {
    $app = $this->adapter->create('api', writeTestDesired(), $this->ctx);
    $before = $app->fresh()->custom_labels;
    $this->adapter->update($app->fresh(), writeTestDesired(['domains' => ['https://new.acme.com']]), $this->ctx);

    expect($app->fresh()->custom_labels)->not->toBe($before)
        ->and(base64_decode($app->fresh()->custom_labels))->toContain('new.acme.com');
});

it('gives the internal url, host, and port as references', function () {
    $app = $this->adapter->create('api', writeTestDesired(['ports' => ['exposes' => '8080,9000']]), $this->ctx);

    expect($this->adapter->referenceValue($app, 'host'))->toBe($app->uuid)
        ->and($this->adapter->referenceValue($app, 'port'))->toBe('8080')
        ->and($this->adapter->referenceValue($app, 'internal_url'))->toBe("http://{$app->uuid}:8080");
});

it('treats health check timings from the Dockerfile as derived while the file leaves the health check off', function () {
    $desired = writeTestDesired();
    $app = $this->adapter->create('api', $desired, $this->ctx);
    $app->forceFill(['custom_healthcheck_found' => true, 'health_check_retries' => 3, 'health_check_start_period' => 1, 'health_check_timeout' => 3])->save();

    expect(writeTestPlanIsEmpty($desired))->toBeTrue();

    $this->adapter->update($app->fresh(), writeTestDesired(['ports' => ['exposes' => '8080']]), $this->ctx);
    expect($app->fresh()->health_check_retries)->toBe(3);

    $this->adapter->update($app->fresh(), writeTestDesired(['health_check' => ['enabled' => true, 'retries' => 7]]), $this->ctx);
    expect($app->fresh()->health_check_retries)->toBe(7)
        ->and($app->fresh()->health_check_timeout)->toBe(5);
});
