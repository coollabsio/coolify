<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeApplication(int $environmentId, int $destinationId, ?string $gitCommitSha): Application
{
    $attributes = [
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
    ];

    if ($gitCommitSha !== null) {
        $attributes['git_commit_sha'] = $gitCommitSha;
    }

    return Application::factory()->create($attributes);
}

describe('queue_application_deployment commit resolution', function () {
    test('records a team audit event when a user queues a deployment', function () {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        session(['currentTeam' => $this->team]);
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        queue_application_deployment($application, 'audit-deploy-uuid');

        $this->assertDatabaseHas('audit_events', [
            'team_id' => $this->team->id,
            'event' => 'ui.application.deployed',
            'resource_uuid' => $application->uuid,
        ]);
    });

    test('uses the deployed application team for the audit event', function () {
        $actorTeam = Team::factory()->create();
        $user = User::factory()->create();
        $actorTeam->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        session()->forget('currentTeam');
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        queue_application_deployment($application, 'resource-team-audit-deploy');

        $event = AuditEvent::query()->where('event', 'ui.application.deployed')->sole();

        expect($event->team_id)->toBe($this->team->id)
            ->and($event->team_id)->not->toBe($actorTeam->id)
            ->and($event->resource_uuid)->toBe($application->uuid)
            ->and($event->metadata)->not->toHaveKey('team_id');
    });

    test('records only the rollback audit event when a user queues a rollback', function () {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');
        AuditEvent::query()->delete();

        queue_application_deployment(
            application: $application,
            deployment_uuid: 'audit-rollback-uuid',
            commit: 'previous-commit',
            rollback: true,
        );

        expect(AuditEvent::query()->pluck('event')->all())->toBe([
            'ui.application.rollback',
        ]);
    });

    test('uses application git_commit_sha when commit parameter omitted', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-1',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-1')->first();
        expect($deployment)->not->toBeNull();
        expect($deployment->commit)->toBe($pinnedSha);
    });

    test('falls back to HEAD when both commit parameter and git_commit_sha are unset', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-2',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-2')->first();
        expect($deployment->commit)->toBe('HEAD');
    });

    test('explicit commit parameter overrides application git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $webhookSha = '111222333444555666777888999000aaabbbccc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-3',
            commit: $webhookSha,
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-3')->first();
        expect($deployment->commit)->toBe($webhookSha);
    });

    test('treats empty string commit parameter as unset and uses git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-4',
            commit: '',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-4')->first();
        expect($deployment->commit)->toBe($pinnedSha);
    });
});
