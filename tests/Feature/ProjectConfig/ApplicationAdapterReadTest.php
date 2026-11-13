<?php

use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\StandaloneDocker;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\Adapters\ApplicationAdapter;
use App\Services\ProjectConfig\ApplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->make(['environment_id' => $this->fx['environment']->id]);
    $this->link->setRelation('environment', $this->fx['environment']);
    $this->ctx = ApplyContext::for($this->link);
    $this->adapter = app(AdapterRegistry::class)->for('application');
    $this->githubApp = GithubApp::forceCreate([
        'uuid' => 'gh1', 'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $this->fx['team']->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fx['team']->id])->id,
    ]);
});

function readTestApp(array $attributes = []): Application
{
    $fx = test()->fx;

    return Application::factory()->create(array_merge([
        'name' => 'api',
        'environment_id' => $fx['environment']->id,
        'destination_id' => $fx['destination']->id,
        'destination_type' => $fx['destination']->getMorphClass(),
        'source_type' => GithubApp::class,
        'source_id' => test()->githubApp->id,
        'git_repository' => 'acme/api',
        'git_branch' => 'main',
        'build_pack' => 'dockerfile',
        'ports_exposes' => '3000',
        'fqdn' => 'HTTPS://Api.acme.com/,https://www.acme.com',
        'manual_webhook_secret_github' => 'hook-secret-value',
        'http_basic_auth_password' => 'basic-secret-value',
    ], $attributes));
}

it('is registered for the application type', function () {
    expect($this->adapter)->toBeInstanceOf(ApplicationAdapter::class);
});

it('reads an application into file form', function () {
    $read = $this->adapter->read(readTestApp(), $this->ctx);

    expect($read['type'])->toBe('application')
        ->and($read['name'])->toBe('api')
        ->and($read['server'])->toBe('prod-1')
        ->and($read['source'])->toEqual(['repository' => 'acme/api', 'branch' => 'main', 'github_app' => 'acme-app'])
        ->and($read['build'])->toBe(['pack' => 'dockerfile'])
        ->and($read['ports'])->toBe(['exposes' => '3000']);
});

it('normalizes domains', function () {
    $read = $this->adapter->read(readTestApp(), $this->ctx);

    expect($read['domains'])->toBe(['https://api.acme.com', 'https://www.acme.com']);
});

it('leaves out values that equal the default', function () {
    $read = $this->adapter->read(readTestApp(['health_check_path' => '/', 'limits_memory' => '0']), $this->ctx);

    expect($read)->not->toHaveKey('health_check')
        ->not->toHaveKey('limits')
        ->not->toHaveKey('destination');
});

it('reads changed settings and nested groups', function () {
    $app = readTestApp(['health_check_enabled' => true, 'health_check_path' => '/health', 'limits_memory' => '512m']);
    $app->settings->update(['is_auto_deploy_enabled' => false]);

    $read = $this->adapter->read($app->fresh(), $this->ctx);

    expect($read['health_check'])->toBe(['enabled' => true, 'path' => '/health'])
        ->and($read['limits'])->toBe(['memory' => '512m'])
        ->and($read['settings'])->toBe(['is_auto_deploy_enabled' => false]);
});

it('never reads secret columns', function () {
    $json = json_encode($this->adapter->read(readTestApp(), $this->ctx));

    expect($json)->not->toContain('hook-secret-value')
        ->not->toContain('basic-secret-value');
});

it('names the destination when the server has more than one', function () {
    StandaloneDocker::forceCreate(['name' => 'second', 'network' => 'second-net', 'server_id' => $this->fx['server']->id, 'uuid' => 'sd2']);

    expect($this->adapter->read(readTestApp(), $this->ctx)['destination'])->toBe($this->fx['destination']->network);
});

it('reads a deploy key source by key name', function () {
    $key = PrivateKey::find($this->githubApp->private_key_id);
    $key->update(['name' => 'deploy-key']);
    $read = $this->adapter->read(readTestApp(['source_type' => null, 'source_id' => null, 'private_key_id' => $key->id, 'git_repository' => 'git@github.com:acme/api.git']), $this->ctx);

    expect($read['source'])->toEqual(['repository' => 'git@github.com:acme/api.git', 'branch' => 'main', 'deploy_key' => 'deploy-key']);
});

it('lists unkeyed resources of the environment only', function () {
    $unkeyed = readTestApp();
    readTestApp(['name' => 'keyed', 'config_key' => 'keyed']);

    expect($this->adapter->unkeyed($this->fx['environment'])->pluck('id')->all())->toBe([$unkeyed->id])
        ->and($this->adapter->find($this->fx['environment'], 'keyed')?->name)->toBe('keyed')
        ->and($this->adapter->findByUuid($this->fx['environment'], $unkeyed->uuid)?->id)->toBe($unkeyed->id);
});

it('makes desired state canonical: fills the name and drops defaults', function () {
    $canonical = $this->adapter->canonical('api', [
        'type' => 'application',
        'server' => 'prod-1',
        'domains' => ['HTTPS://Api.acme.com/'],
        'health_check' => ['path' => '/'],
        'settings' => ['is_auto_deploy_enabled' => true],
    ]);

    expect($canonical)->toEqual(['type' => 'application', 'name' => 'api', 'server' => 'prod-1', 'domains' => ['https://api.acme.com']]);
});
