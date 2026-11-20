<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id])->fresh();
    $this->deployed = [];
    $deployed = &$this->deployed;
    app()->instance(HeldDeployments::class, new class($deployed) extends HeldDeployments
    {
        public function __construct(private array &$log) {}

        public function deploy(Application $application, ?string $commit): void
        {
            $this->log[] = [$application->config_key ?? $application->name, $commit];
        }
    });
});

function applierFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
}

function applierApp(array $overrides = []): array
{
    return array_replace([
        'type' => 'application',
        'server' => 'prod-1',
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'],
        'ports' => ['exposes' => '3000'],
    ], $overrides);
}

function applierRecord(array $resources, array $attributes = []): EnvironmentConfigRevision
{
    return app(ConfigRevisions::class)->record(test()->link, applierFile($resources), 'api', ...$attributes);
}

it('applies a plan and records it', function () {
    $revision = applierRecord(['api' => applierApp()]);
    expect($revision->status)->toBe(ConfigRevisionStatus::Planned);

    app(ConfigApplier::class)->apply($revision);

    $revision->refresh();
    expect($revision->status)->toBe(ConfigRevisionStatus::Applied)
        ->and($revision->applied_at)->not->toBeNull()
        ->and($this->link->fresh()->last_applied_revision_id)->toBe($revision->id)
        ->and(Application::where('config_key', 'api')->count())->toBe(1)
        ->and($this->deployed)->toBe([['api', null]])
        ->and(AuditEvent::where('event', 'system.project_config.applied')->count())->toBe(1);
});

it('applies one revision only once', function () {
    $revision = applierRecord(['api' => applierApp()]);

    app(ConfigApplier::class)->apply($revision);
    app(ConfigApplier::class)->apply(EnvironmentConfigRevision::find($revision->id));

    expect(AuditEvent::where('event', 'system.project_config.applied')->count())->toBe(1)
        ->and(Application::where('config_key', 'api')->count())->toBe(1);
});

it('rejects a stale plan and plans again', function () {
    $revision = applierRecord(['api' => applierApp()]);
    Application::factory()->create([
        'name' => 'handmade', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);

    app(ConfigApplier::class)->apply($revision);

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($revision->fresh()->error)->toContain('changed')
        ->and(EnvironmentConfigRevision::where('link_id', $this->link->id)->count())->toBe(2)
        ->and(Application::where('config_key', 'api')->count())->toBe(1);
});

it('holds a destructive plan until it is approved', function () {
    app(ConfigApplier::class)->apply(applierRecord(['api' => applierApp(), 'worker' => applierApp()]));
    $revision = applierRecord(['api' => applierApp()]);

    expect($revision->status)->toBe(ConfigRevisionStatus::WaitingApproval);
    app(ConfigApplier::class)->apply($revision);
    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::WaitingApproval);
    Queue::assertNotPushed(DeleteResourceJob::class);

    $revision->update(['approved_at' => now(), 'approved_by' => $this->fx['user']->id, 'delete_data' => ['worker']]);
    app(ConfigApplier::class)->apply($revision->fresh());

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Applied);
    Queue::assertPushed(DeleteResourceJob::class, fn ($job) => $job->deleteVolumes === true);
});

it('writes nothing when one resource fails, and fails the held deploys', function () {
    $held = Application::factory()->create([
        'name' => 'held', 'config_key' => null, 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);
    $revision = applierRecord(['api' => applierApp(), 'worker' => applierApp(['env' => ['URL' => ['ref' => 'resource.api.internal_url']]])]);
    $revision->update(['held_deployments' => [['application_uuid' => $held->uuid, 'commit_sha' => 'abc']]]);
    Application::saving(function (Application $application) {
        if ($application->config_key === 'worker') {
            throw new RuntimeException('Disk full');
        }
    });

    app(ConfigApplier::class)->apply($revision->fresh());

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($revision->fresh()->error)->toContain('Disk full')
        ->and(Application::whereNotNull('config_key')->count())->toBe(0)
        ->and(ApplicationDeploymentQueue::where('application_id', $held->id)->value('status'))->toBe('failed');
});

it('supersedes older plans and keeps their held deploys', function () {
    $held = Application::factory()->create([
        'name' => 'held', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);
    $older = applierRecord(['api' => applierApp()]);
    $older->update(['held_deployments' => [['application_uuid' => $held->uuid, 'commit_sha' => 'old']]]);
    $newer = applierRecord(['api' => applierApp(['ports' => ['exposes' => '8080']])]);
    $newer->update(['held_deployments' => [['application_uuid' => $held->uuid, 'commit_sha' => 'new']]]);

    app(ConfigApplier::class)->apply($newer->fresh());

    expect($older->fresh()->status)->toBe(ConfigRevisionStatus::Superseded)
        ->and($this->deployed)->toContain(['held', 'new'])
        ->and(collect($this->deployed)->where(0, 'held')->count())->toBe(1);
});

it('does not deploy a resource with a missing required variable', function () {
    $revision = applierRecord(['api' => applierApp(['env' => ['STRIPE_KEY' => ['required' => true]]])]);

    app(ConfigApplier::class)->apply($revision);

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Applied)
        ->and($this->deployed)->toBe([]);
});

it('releases a revision that stayed in applying', function () {
    $stuck = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'status' => ConfigRevisionStatus::Applying, 'started_at' => now()->subMinutes(20)]);
    $running = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'status' => ConfigRevisionStatus::Applying, 'started_at' => now()->subMinutes(2)]);

    $this->artisan('project-config:release-stuck')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($stuck->fresh()->error)->toBe('Apply did not finish.')
        ->and($running->fresh()->status)->toBe(ConfigRevisionStatus::Applying);
});
