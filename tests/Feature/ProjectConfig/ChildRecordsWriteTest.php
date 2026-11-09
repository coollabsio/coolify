<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\IntegrationToken;
use App\Models\Server;
use App\Models\Tag;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ServerStorageSaveJob::class]);
    $this->fx = projectConfigFixture();
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    $this->ctx = ApplyContext::for($link->fresh());
    $this->adapter = app(AdapterRegistry::class)->for('application');
    $this->application = ManagedResources::whileApplying(fn () => $this->adapter->create('api', childWriteDesired(), $this->ctx));
});

function childWriteDesired(array $extra = []): array
{
    return array_merge([
        'type' => 'application',
        'server' => 'prod-1',
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'],
        'ports' => ['exposes' => '3000'],
    ], $extra);
}

function childWrite(array $extra): Application
{
    $app = test()->application->fresh();
    ManagedResources::whileApplying(fn () => test()->adapter->update($app, childWriteDesired($extra), test()->ctx));

    return $app->fresh();
}

function childWriteValue(Application $app, string $key, bool $preview = false): ?string
{
    return EnvironmentVariable::where('resourceable_type', $app->getMorphClass())->where('resourceable_id', $app->id)
        ->where('key', $key)->where('is_preview', $preview)->first()?->value;
}

it('writes plain values and keeps one production and one preview row', function () {
    $app = childWrite(['env' => ['NODE_ENV' => ['value' => 'production']]]);
    childWrite(['env' => ['NODE_ENV' => ['value' => 'production']]]);

    expect(childWriteValue($app, 'NODE_ENV'))->toBe('production')
        ->and(childWriteValue($app, 'NODE_ENV', true))->toBe('production')
        ->and(EnvironmentVariable::where('resourceable_id', $app->id)->where('key', 'NODE_ENV')->count())->toBe(2);
});

it('never changes a required value that was set in Coolify', function () {
    $app = childWrite(['env' => ['STRIPE_KEY' => ['required' => true]]]);
    EnvironmentVariable::where('resourceable_id', $app->id)->where('key', 'STRIPE_KEY')->where('is_preview', false)->first()->update(['value' => 'sk_live']);

    $app = childWrite(['env' => ['STRIPE_KEY' => ['required' => true]]]);

    expect(childWriteValue($app, 'STRIPE_KEY'))->toBe('sk_live');
});

it('keeps a generated value and replaces it when the rotation grows', function () {
    $app = childWrite(['env' => ['SESSION' => ['generate' => 'password', 'length' => 32]]]);
    $first = childWriteValue($app, 'SESSION');
    childWrite(['env' => ['SESSION' => ['generate' => 'password', 'length' => 32]]]);
    $kept = childWriteValue($app, 'SESSION');
    childWrite(['env' => ['SESSION' => ['generate' => 'password', 'length' => 32, 'rotation' => 1]]]);

    expect(strlen($first))->toBe(32)
        ->and($kept)->toBe($first)
        ->and(childWriteValue($app, 'SESSION'))->not->toBe($first)
        ->and(childWriteValue($app, 'SESSION', true))->toBe(childWriteValue($app, 'SESSION'));
});

it('generates uuid and base64 values', function () {
    $app = childWrite(['env' => ['ID' => ['generate' => 'uuid'], 'KEY' => ['generate' => 'base64']]]);

    expect(childWriteValue($app, 'ID'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and(strlen(childWriteValue($app, 'KEY')))->toBe(32);
});

it('stores shared, vault, and resource references', function () {
    $this->ctx->resolvedRefs['resource.db.internal_url'] = 'postgres://db:5432/app';
    $app = childWrite(['env' => [
        'SENDGRID' => ['ref' => 'team.SENDGRID_KEY'],
        'API_KEY' => ['ref' => 'vault.API_KEY'],
        'DATABASE_URL' => ['ref' => 'resource.db.internal_url'],
    ]]);

    expect(childWriteValue($app, 'SENDGRID'))->toBe('{{team.SENDGRID_KEY}}')
        ->and(childWriteValue($app, 'API_KEY'))->toBe('{{vault.API_KEY}}')
        ->and(childWriteValue($app, 'DATABASE_URL'))->toBe('postgres://db:5432/app');
});

it('removes variables that leave the file, also their preview rows', function () {
    $app = childWrite(['env' => ['A' => ['value' => '1'], 'B' => ['value' => '2']]]);
    $app = childWrite(['env' => ['A' => ['value' => '1']]]);

    expect(EnvironmentVariable::where('resourceable_id', $app->id)->where('key', 'B')->count())->toBe(0);
});

it('writes an explicit preview value', function () {
    $app = childWrite(['env' => ['API_URL' => ['value' => 'https://api.acme.com', 'preview' => ['value' => 'https://pr.acme.com']]]]);

    expect(childWriteValue($app, 'API_URL'))->toBe('https://api.acme.com')
        ->and(childWriteValue($app, 'API_URL', true))->toBe('https://pr.acme.com');
});

it('reads back what it wrote for every child group', function () {
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $token = IntegrationToken::factory()->create(['team_id' => $this->fx['team']->id, 'provider' => 'doppler', 'name' => 'doppler-prod', 'capabilities' => ['secrets']]);
    $desired = childWriteDesired([
        'env' => ['NODE_ENV' => ['value' => 'production', 'buildtime' => false], 'TOKEN' => ['required' => true]],
        'storages' => ['data' => ['mount' => '/data'], 'logs' => ['mount' => '/logs', 'host_path' => '/var/log/api']],
        'files' => ['/etc/app.conf' => ['content' => "a=1\n"], '/cache' => ['is_directory' => true]],
        'scheduled_tasks' => ['cleanup' => ['command' => 'php artisan cleanup', 'frequency' => 'daily', 'timeout' => 600]],
        'tags' => ['web', 'api'],
        'secret_manager' => ['token' => 'doppler-prod', 'settings' => ['project' => 'api', 'config' => 'prd']],
        'additional_servers' => [['server' => 'prod-2']],
    ]);
    $app = childWrite(array_diff_key($desired, childWriteDesired()));

    expect($this->adapter->read($app, $this->ctx))->toEqual($this->adapter->canonical('api', $desired))
        ->and($app->persistentStorages()->pluck('name')->all())->toContain($app->uuid.'-data');

    $app = childWrite([]);
    expect($this->adapter->read($app, $this->ctx))->not->toHaveKeys(['env', 'storages', 'files', 'scheduled_tasks', 'tags', 'secret_manager', 'additional_servers']);
});

it('does not use tags of another team', function () {
    $other = projectConfigFixture();
    $foreign = Tag::create(['name' => 'web', 'team_id' => $other['team']->id]);

    $app = childWrite(['tags' => ['web']]);

    expect($app->tags()->first()->id)->not->toBe($foreign->id)
        ->and($app->tags()->first()->team_id)->toBe($this->fx['team']->id);
});

it('fails validation for unknown child references before any write', function () {
    $other = projectConfigFixture();
    IntegrationToken::factory()->create(['team_id' => $other['team']->id, 'provider' => 'doppler', 'name' => 'foreign', 'capabilities' => ['secrets']]);
    $errors = $this->adapter->validate('api', childWriteDesired([
        'secret_manager' => ['token' => 'foreign'],
        'additional_servers' => [['server' => 'nope']],
        'files' => ['../etc/passwd' => ['content' => 'x']],
    ]), $this->ctx);

    expect(array_map(fn ($e) => $e->path, $errors))->toContain(
        'resources.api.secret_manager.token',
        'resources.api.additional_servers.0.server',
        'resources.api.files.../etc/passwd',
    );
});
