<?php

use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->service = Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);

    Queue::fake();
});

function scheduledTaskAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'scheduled-task-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

describe('execute', function () {
    test('write-only token cannot execute an application or service task', function () {
        $headers = scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']);
        $applicationTask = ScheduledTask::factory()->create(['application_id' => $this->application->id, 'team_id' => $this->team->id]);
        $serviceTask = ScheduledTask::factory()->create(['service_id' => $this->service->id, 'team_id' => $this->team->id]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$applicationTask->uuid}/execute")
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson("/api/v1/services/{$this->service->uuid}/scheduled-tasks/{$serviceTask->uuid}/execute")
            ->assertForbidden();

        Queue::assertNotPushed(ScheduledTaskJob::class);
    });

    test('deploy token can execute an application or service task', function () {
        $headers = scheduledTaskAbilityHeaders($this->user, $this->team->id, ['deploy']);
        $applicationTask = ScheduledTask::factory()->create(['application_id' => $this->application->id, 'team_id' => $this->team->id]);
        $serviceTask = ScheduledTask::factory()->create(['service_id' => $this->service->id, 'team_id' => $this->team->id]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$applicationTask->uuid}/execute")
            ->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/v1/services/{$this->service->uuid}/scheduled-tasks/{$serviceTask->uuid}/execute")
            ->assertOk();

        Queue::assertPushed(ScheduledTaskJob::class, 2);
    });
});

describe('create', function () {
    test('write-only token cannot create an enabled task', function (array $payload) {
        $headers = scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']);
        $task = ['name' => 'nightly', 'command' => 'id', 'frequency' => '0 0 1 1 *'] + $payload;

        $this->withHeaders($headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks", $task)
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson("/api/v1/services/{$this->service->uuid}/scheduled-tasks", $task)
            ->assertForbidden();

        expect(ScheduledTask::count())->toBe(0);
    })->with([
        'enabled by default' => [[]],
        'enabled explicitly' => [['enabled' => true]],
    ]);

    test('write-only token can create a disabled task', function () {
        $this->withHeaders(scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks", [
                'name' => 'draft',
                'command' => 'id',
                'frequency' => '0 0 1 1 *',
                'enabled' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('enabled', false);
    });

    test('write and deploy token can create an enabled task', function () {
        $this->withHeaders(scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks", [
                'name' => 'nightly',
                'command' => 'id',
                'frequency' => '0 0 1 1 *',
            ])
            ->assertCreated()
            ->assertJsonPath('enabled', true);
    });
});

describe('update', function () {
    test('write-only token cannot change an enabled task', function () {
        $task = ScheduledTask::factory()->create([
            'application_id' => $this->application->id,
            'team_id' => $this->team->id,
            'command' => 'echo safe',
            'enabled' => true,
        ]);

        $this->withHeaders(scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}", ['command' => 'id'])
            ->assertForbidden();

        expect($task->fresh()->command)->toBe('echo safe');
    });

    test('write-only token cannot enable a disabled task', function () {
        $task = ScheduledTask::factory()->create([
            'service_id' => $this->service->id,
            'team_id' => $this->team->id,
            'enabled' => false,
        ]);

        $this->withHeaders(scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']))
            ->patchJson("/api/v1/services/{$this->service->uuid}/scheduled-tasks/{$task->uuid}", ['enabled' => true])
            ->assertForbidden();

        expect($task->fresh()->enabled)->toBeFalse();
    });

    test('write-only token can change or disable a task that stays disabled', function () {
        $task = ScheduledTask::factory()->create([
            'application_id' => $this->application->id,
            'team_id' => $this->team->id,
            'enabled' => true,
        ]);
        $headers = scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}", ['enabled' => false])
            ->assertOk();
        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}", ['command' => 'id'])
            ->assertOk();

        expect($task->fresh())->enabled->toBeFalse()->command->toBe('id');
    });

    test('write and deploy token can change an enabled task', function () {
        $task = ScheduledTask::factory()->create([
            'application_id' => $this->application->id,
            'team_id' => $this->team->id,
            'enabled' => true,
        ]);

        $this->withHeaders(scheduledTaskAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}", ['command' => 'id'])
            ->assertOk();

        expect($task->fresh()->command)->toBe('id');
    });
});
