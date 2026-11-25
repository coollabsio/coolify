<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledTask;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\Tag;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ServerStorageSaveJob::class]);
    $this->fx = projectConfigFixture();
    $link = EnvironmentConfigLink::factory()->make(['environment_id' => $this->fx['environment']->id]);
    $link->setRelation('environment', $this->fx['environment']);
    $this->ctx = ApplyContext::for($link);
    $this->adapter = app(AdapterRegistry::class)->for('application');
    $this->application = Application::factory()->create([
        'name' => 'api',
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id,
        'destination_type' => $this->fx['destination']->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
});

function childReadEnv(Application $app, string $key, ?string $value, array $extra = []): EnvironmentVariable
{
    $variable = EnvironmentVariable::create(array_merge([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => $app->getMorphClass(),
        'resourceable_id' => $app->id,
    ], $extra));
    if (! ($extra['is_preview'] ?? false)) {
        EnvironmentVariable::where('resourceable_id', $app->id)->where('key', $key)->where('is_preview', true)->delete();
    }

    return $variable;
}

function childRead(): array
{
    return test()->adapter->read(test()->application->fresh(), test()->ctx);
}

it('reads variables in their stored form', function () {
    childReadEnv($this->application, 'PLAIN', 'secret-in-db');
    childReadEnv($this->application, 'NODE_ENV', 'production', ['config_meta' => ['form' => 'value']]);
    childReadEnv($this->application, 'SENDGRID', '{{team.SENDGRID_KEY}}');
    childReadEnv($this->application, 'SESSION', 'x', ['config_meta' => ['form' => 'generate', 'generate' => 'password', 'rotation' => 2]]);
    childReadEnv($this->application, 'DB_URL', 'postgres://resolved', ['config_meta' => ['form' => 'ref', 'ref' => 'resource.db.internal_url']]);
    childReadEnv($this->application, 'RUNTIME_ONLY', 'y', ['config_meta' => ['form' => 'value'], 'is_buildtime' => false, 'is_multiline' => true]);

    expect(childRead()['env'])->toEqual([
        'PLAIN' => ['required' => true],
        'NODE_ENV' => ['value' => 'production'],
        'SENDGRID' => ['ref' => 'team.SENDGRID_KEY'],
        'SESSION' => ['generate' => 'password', 'rotation' => 2],
        'DB_URL' => ['ref' => 'resource.db.internal_url'],
        'RUNTIME_ONLY' => ['value' => 'y', 'buildtime' => false, 'multiline' => true],
    ]);
});

it('never reads the value of a required variable', function () {
    childReadEnv($this->application, 'STRIPE_KEY', 'sk_live_secret_value', ['config_meta' => ['form' => 'required']]);

    expect(json_encode(childRead()))->not->toContain('sk_live_secret_value');
});

it('keeps a multiline value byte for byte', function () {
    $value = "line one\r\n  line two  \n\ttab";
    childReadEnv($this->application, 'CERT', $value, ['config_meta' => ['form' => 'value'], 'is_multiline' => true]);

    expect(childRead()['env']['CERT']['value'])->toBe($value);
});

it('shows a preview entry only when it differs', function () {
    childReadEnv($this->application, 'SAME', 'a', ['config_meta' => ['form' => 'value']]);
    childReadEnv($this->application, 'SAME', 'a', ['config_meta' => ['form' => 'value'], 'is_preview' => true]);
    childReadEnv($this->application, 'DIFF', 'a', ['config_meta' => ['form' => 'value']]);
    childReadEnv($this->application, 'DIFF', 'b', ['config_meta' => ['form' => 'value'], 'is_preview' => true]);

    $env = childRead()['env'];

    expect($env['SAME'])->toBe(['value' => 'a'])
        ->and($env['DIFF'])->toEqual(['value' => 'a', 'preview' => ['value' => 'b']]);
});

it('skips generated SERVICE_ variables of compose applications', function () {
    $this->application->update(['build_pack' => 'dockercompose']);
    childReadEnv($this->application, 'SERVICE_PASSWORD_DB', 'generated');
    childReadEnv($this->application, 'APP_KEY', 'x', ['config_meta' => ['form' => 'value']]);

    expect(array_keys(childRead()['env']))->toBe(['APP_KEY']);
});

it('reads volumes by their name without the resource uuid', function () {
    LocalPersistentVolume::create(['name' => $this->application->uuid.'-data', 'mount_path' => '/data', 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
    LocalPersistentVolume::create(['name' => $this->application->uuid.'-logs', 'mount_path' => '/logs', 'host_path' => '/var/log/api', 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
    LocalPersistentVolume::create(['name' => 'shared-cache', 'mount_path' => '/cache', 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);

    expect(childRead()['storages'])->toEqual([
        'data' => ['mount' => '/data'],
        'logs' => ['mount' => '/logs', 'host_path' => '/var/log/api'],
        'shared-cache' => ['mount' => '/cache', 'volume_name' => 'shared-cache'],
    ]);
});

it('reads file mounts by mount path', function () {
    LocalFileVolume::create(['fs_path' => '/data/coolify/applications/x/nginx.conf', 'mount_path' => '/etc/nginx/nginx.conf', 'content' => "worker_processes 1;\n", 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
    LocalFileVolume::create(['fs_path' => '/data/coolify/applications/x/cache', 'mount_path' => '/cache', 'is_directory' => true, 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);

    expect(childRead()['files'])->toEqual([
        '/cache' => ['is_directory' => true],
        '/etc/nginx/nginx.conf' => ['content' => "worker_processes 1;\n"],
    ]);
});

it('reads scheduled tasks by name', function () {
    ScheduledTask::create(['name' => 'cleanup', 'command' => 'php artisan cleanup', 'frequency' => '0 * * * *', 'application_id' => $this->application->id, 'team_id' => $this->fx['team']->id]);
    ScheduledTask::create(['name' => 'report', 'command' => 'php artisan report', 'frequency' => 'daily', 'container' => 'worker', 'timeout' => 600, 'enabled' => false, 'application_id' => $this->application->id, 'team_id' => $this->fx['team']->id]);

    expect(childRead()['scheduled_tasks'])->toEqual([
        'cleanup' => ['command' => 'php artisan cleanup', 'frequency' => '0 * * * *'],
        'report' => ['command' => 'php artisan report', 'frequency' => 'daily', 'container' => 'worker', 'timeout' => 600, 'enabled' => false],
    ]);
});

it('reads tags as a sorted list', function () {
    $this->application->tags()->attach([
        Tag::create(['name' => 'web', 'team_id' => $this->fx['team']->id])->id,
        Tag::create(['name' => 'api', 'team_id' => $this->fx['team']->id])->id,
    ]);

    expect(childRead()['tags'])->toBe(['api', 'web']);
});

it('reads the secret manager by token name', function () {
    $token = IntegrationToken::factory()->create(['team_id' => $this->fx['team']->id, 'provider' => 'doppler', 'name' => 'doppler-prod', 'capabilities' => ['secrets']]);
    SecretManagerLink::create(['resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id, 'integration_token_id' => $token->id, 'settings' => ['project' => 'api', 'config' => 'prd']]);

    expect(childRead()['secret_manager'])->toEqual(['token' => 'doppler-prod', 'settings' => ['config' => 'prd', 'project' => 'api']]);
});

it('reads additional servers by name', function () {
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $destination = $other->standaloneDockers()->first();
    $this->application->additional_servers()->attach($other->id, ['standalone_docker_id' => $destination->id]);

    expect(childRead()['additional_servers'])->toBe([['server' => 'prod-2']]);
});

it('leaves out empty child groups', function () {
    expect(childRead())->not->toHaveKeys(['env', 'storages', 'files', 'scheduled_tasks', 'tags', 'secret_manager', 'additional_servers']);
});
