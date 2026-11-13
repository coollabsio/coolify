<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Jobs\DeleteResourceJob;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\StandalonePostgresql;
use App\Notifications\ProjectConfig\ConfigApplyFailed;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigPushRouter;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'repository' => 'acme/api',
        'branch' => 'main', 'file_path' => 'coolify.json', 'read_server_id' => $this->fx['server']->id,
    ])->fresh();
    $this->files = [];
    $this->deployed = [];
    $files = &$this->files;
    $deployed = &$this->deployed;
    app()->instance(RepositoryFileReader::class, new class($files) extends RepositoryFileReader
    {
        public function __construct(private array &$files) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            $contents = $this->files[$commit ?? 'head'] ?? null;

            return new RepositoryFile($contents, $commit ?? str_repeat('a', 40), $contents === null);
        }
    });
    app()->instance(HeldDeployments::class, new class($deployed) extends HeldDeployments
    {
        public function __construct(private array &$log) {}

        public function deploy(Application $application, ?string $commit): void
        {
            $this->log[] = [$application->config_key ?? $application->name, $commit];
        }

        public function restart(Model $resource, bool $created): void {}
    });
});

function heldFile(array $resources, array $environment = []): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main', ...$environment]]]);
}

function heldApp(array $overrides = []): array
{
    return array_replace([
        'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
    ], $overrides);
}

function heldApply(string $file, array $attributes = []): EnvironmentConfigRevision
{
    $revision = app(ConfigRevisions::class)->record(test()->link->fresh(), $file, 'push');
    if ($attributes !== []) {
        $revision->update($attributes);
    }
    app(ConfigApplier::class)->apply($revision->fresh());

    return $revision->fresh();
}

function heldPlan(string $file): ConfigPlan
{
    return app(ConfigPlanner::class)->plan(app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse($file), 'main'), ApplyContext::for(test()->link));
}

function heldUnmanagedApp(string $name = 'web'): Application
{
    return Application::factory()->create([
        'name' => $name, 'environment_id' => test()->fx['environment']->id,
        'destination_id' => test()->fx['destination']->id, 'destination_type' => test()->fx['destination']->getMorphClass(),
    ]);
}

it('applies the same import a second time without errors or changes', function () {
    $app = heldUnmanagedApp('api');
    $file = heldFile(['api' => heldApp()], ['import' => [['to' => 'api', 'uuid' => $app->uuid]]]);

    expect(heldApply($file, ['approved_at' => now()])->status)->toBe(ConfigRevisionStatus::Applied);

    $plan = heldPlan($file);
    expect($plan->errors)->toBe([])
        ->and($plan->changes)->toBe([]);
});

it('moves the held deploys of a skipped push to the newest push', function () {
    $web = heldUnmanagedApp();
    $this->files[str_repeat('2', 40)] = heldFile(['api' => heldApp()]);
    $this->link->update(['pending_commit_sha' => str_repeat('2', 40)]);

    PlanEnvironmentConfigJob::dispatchSync($this->link->fresh(), 'push', str_repeat('1', 40), [$web->uuid]);

    expect(EnvironmentConfigRevision::where('commit_sha', str_repeat('1', 40))->first()->status)->toBe(ConfigRevisionStatus::Skipped)
        ->and($this->deployed)->toContain(['web', str_repeat('2', 40)]);
});

it('keeps the held deploys of an unflushed push when a newer push arrives', function () {
    Queue::fake([PlanEnvironmentConfigJob::class]);
    $web = heldUnmanagedApp();
    $this->link->update(['webhook_secret' => 's', 'pending_commit_sha' => str_repeat('1', 40), 'pending_pushed_at' => now(), 'pending_held_deployments' => [$web->uuid]]);

    $router = new ConfigPushRouter;
    $router->beginPush(null, fn () => true, 'main', str_repeat('2', 40), fn () => true);

    expect($this->link->fresh()->pending_held_deployments)->toBe([$web->uuid]);
    $router->flush();
    Queue::assertPushed(PlanEnvironmentConfigJob::class, fn ($job) => $job->commit === str_repeat('2', 40) && $job->heldApplicationUuids === [$web->uuid]);
});

it('fails the held deploys and notifies when a stuck or manual release fails a revision', function () {
    Notification::fake();
    $this->fx['team']->emailNotificationSettings()->update(['use_instance_email_settings' => true]);
    $web = heldUnmanagedApp();
    $held = [['application_uuid' => $web->uuid, 'commit_sha' => 'abc']];
    $stuck = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'trigger' => 'push', 'status' => ConfigRevisionStatus::Applying, 'started_at' => now()->subMinutes(20), 'held_deployments' => $held]);

    $this->artisan('project-config:release-stuck')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and(ApplicationDeploymentQueue::where('application_id', $web->id)->where('status', 'failed')->count())->toBe(1);
    Notification::assertSentTo($this->fx['team'], ConfigApplyFailed::class);

    $manual = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'trigger' => 'push', 'status' => ConfigRevisionStatus::Applying, 'started_at' => now(), 'held_deployments' => $held]);
    app(ConfigLinks::class)->release($manual, $this->fx['user']);

    expect($manual->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and(ApplicationDeploymentQueue::where('application_id', $web->id)->where('status', 'failed')->count())->toBe(2);
});

it('deploys the held deploys of waiting revisions when the link is disconnected', function () {
    $web = heldUnmanagedApp();
    EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'status' => ConfigRevisionStatus::WaitingApproval, 'held_deployments' => [['application_uuid' => $web->uuid, 'commit_sha' => 'abc']]]);

    app(ConfigLinks::class)->disconnect($this->link, $this->fx['user']);

    expect($this->deployed)->toBe([['web', 'abc']]);
});

it('rewrites and redeploys a variable whose referenced database changed', function () {
    $api = heldApp(['env' => ['DATABASE_URL' => ['ref' => 'resource.db.internal_url']]]);
    heldApply(heldFile(['db' => ['type' => 'postgresql', 'server' => 'prod-1'], 'api' => $api]));
    $this->deployed = [];

    $revision = heldApply(heldFile(['db' => ['type' => 'postgresql', 'server' => 'prod-1', 'credentials' => ['database' => 'app']], 'api' => $api]), ['approved_at' => now()]);

    $db = StandalonePostgresql::where('config_key', 'db')->first();
    $app = Application::where('config_key', 'api')->first();
    expect($revision->status)->toBe(ConfigRevisionStatus::Applied, (string) $revision->error)
        ->and($db->postgres_db)->toBe('app')
        ->and(EnvironmentVariable::where('resourceable_type', $app->getMorphClass())->where('resourceable_id', $app->id)->where('key', 'DATABASE_URL')->where('is_preview', false)->first()->value)->toBe($db->internal_db_url)
        ->and($this->deployed)->toBe([['api', null]]);
});

it('soft deletes a removed application and does not deploy its held deploy', function () {
    heldApply(heldFile(['api' => heldApp(), 'worker' => heldApp()]));
    $worker = Application::where('config_key', 'worker')->first();
    $this->deployed = [];

    $revision = heldApply(heldFile(['api' => heldApp()]), ['approved_at' => now(), 'held_deployments' => [['application_uuid' => $worker->uuid, 'commit_sha' => 'abc']]]);

    expect($revision->status)->toBe(ConfigRevisionStatus::Applied)
        ->and(Application::withTrashed()->find($worker->id)->trashed())->toBeTrue()
        ->and($this->deployed)->toBe([]);
    Queue::assertPushed(DeleteResourceJob::class);
});

it('does not mark a revision applied after it was released during the apply', function () {
    $revision = app(ConfigRevisions::class)->record($this->link->fresh(), heldFile(['api' => heldApp()]), 'push');
    Application::saving(function () use ($revision) {
        EnvironmentConfigRevision::query()->whereKey($revision->id)->update(['status' => ConfigRevisionStatus::Failed->value, 'error' => 'Released by someone.']);
    });

    app(ConfigApplier::class)->apply($revision);

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($revision->fresh()->error)->toBe('Released by someone.');
});

it('applies a planned revision whose apply job was lost', function () {
    Queue::fake([DeleteResourceJob::class, ApplyEnvironmentConfigJob::class]);
    $revision = app(ConfigRevisions::class)->record($this->link->fresh(), heldFile(['api' => heldApp()]), 'push');
    $this->travel(20)->minutes();

    $this->artisan('project-config:release-stuck')->assertSuccessful();

    Queue::assertPushed(ApplyEnvironmentConfigJob::class, fn ($job) => $job->revision->is($revision));
});

it('rejects a reference to a resource property without a value', function () {
    $noPorts = heldApp();
    unset($noPorts['ports']);
    $plan = heldPlan(heldFile([
        'db' => ['type' => 'sqlite', 'server' => 'prod-1'],
        'worker' => $noPorts,
        'api' => heldApp(['env' => [
            'DB_PORT' => ['ref' => 'resource.db.port'],
            'DB_URL' => ['ref' => 'resource.db.internal_url'],
            'WORKER_PORT' => ['ref' => 'resource.worker.port'],
            'WORKER_HOST' => ['ref' => 'resource.worker.host'],
            'WORKER_URL' => ['ref' => 'resource.worker.internal_url'],
        ]]),
    ]));

    expect(array_map(fn ($e) => $e->path, $plan->errors))->toEqualCanonicalizing([
        'resources.api.env.DB_PORT', 'resources.api.env.DB_URL', 'resources.api.env.WORKER_PORT',
    ]);
});

it('drops the config jobs of a deleted link or revision', function () {
    $revision = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id]);

    expect((new PlanEnvironmentConfigJob($this->link, 'push'))->deleteWhenMissingModels)->toBeTrue()
        ->and((new ApplyEnvironmentConfigJob($revision))->deleteWhenMissingModels)->toBeTrue();
});
