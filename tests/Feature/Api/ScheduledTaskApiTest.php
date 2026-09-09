<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // ApiAllowed middleware requires InstanceSettings with id=0
    InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    // Server::booted() auto-creates a StandaloneDocker, reuse it
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    // Project::booted() auto-creates a 'production' Environment, reuse it
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
});

function scheduledTaskAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('GET /api/v1/applications/{uuid}/scheduled-tasks', function () {
    test('returns empty array when no tasks exist', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$application->uuid}/scheduled-tasks");

        $response->assertStatus(200);
        $response->assertJson([]);
    });

    test('returns tasks when they exist', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        ScheduledTask::factory()->create([
            'application_id' => $application->id,
            'team_id' => $this->team->id,
            'name' => 'Test Task',
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$application->uuid}/scheduled-tasks");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'Test Task']);
    });

    test('returns 404 for unknown application uuid', function () {
        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/applications/nonexistent-uuid/scheduled-tasks');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/applications/{uuid}/scheduled-tasks', function () {
    test('creates a task with valid data', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
                'name' => 'Backup',
                'command' => 'php artisan backup',
                'frequency' => '0 0 * * *',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Backup']);

        $this->assertDatabaseHas('scheduled_tasks', [
            'name' => 'Backup',
            'command' => 'php artisan backup',
            'frequency' => '0 0 * * *',
            'application_id' => $application->id,
            'team_id' => $this->team->id,
        ]);
    });

    test('returns 422 when name is missing', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
                'command' => 'echo test',
                'frequency' => '* * * * *',
            ]);

        $response->assertStatus(422);
    });

    test('returns 422 for invalid cron expression', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
                'name' => 'Test',
                'command' => 'echo test',
                'frequency' => 'not-a-cron',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.frequency.0', 'Invalid cron expression or frequency format.');
    });

    test('returns 422 when extra fields are present', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
                'name' => 'Test',
                'command' => 'echo test',
                'frequency' => '* * * * *',
                'unknown_field' => 'value',
            ]);

        $response->assertStatus(422);
    });

    test('defaults timeout and enabled when not provided', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
                'name' => 'Test',
                'command' => 'echo test',
                'frequency' => '* * * * *',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('scheduled_tasks', [
            'name' => 'Test',
            'timeout' => 300,
            'enabled' => true,
        ]);
    });
});

describe('PATCH /api/v1/applications/{uuid}/scheduled-tasks/{task_uuid}', function () {
    test('updates task with partial data', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $task = ScheduledTask::factory()->create([
            'application_id' => $application->id,
            'team_id' => $this->team->id,
            'name' => 'Old Name',
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/{$task->uuid}", [
                'name' => 'New Name',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'New Name']);
    });

    test('returns 404 when task not found', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/nonexistent", [
                'name' => 'Test',
            ]);

        $response->assertStatus(404);
    });
});

describe('DELETE /api/v1/applications/{uuid}/scheduled-tasks/{task_uuid}', function () {
    test('deletes task successfully', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $task = ScheduledTask::factory()->create([
            'application_id' => $application->id,
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/{$task->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Scheduled task deleted.']);

        $this->assertDatabaseMissing('scheduled_tasks', ['uuid' => $task->uuid]);
    });

    test('returns 404 when task not found', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/nonexistent");

        $response->assertStatus(404);
    });
});

describe('GET /api/v1/applications/{uuid}/scheduled-tasks/{task_uuid}/executions', function () {
    test('returns executions for a task', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $task = ScheduledTask::factory()->create([
            'application_id' => $application->id,
            'team_id' => $this->team->id,
        ]);

        ScheduledTaskExecution::create([
            'scheduled_task_id' => $task->id,
            'status' => 'success',
            'message' => 'OK',
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/{$task->uuid}/executions");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['status' => 'success']);
    });

    test('returns 404 when task not found', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/nonexistent/executions");

        $response->assertStatus(404);
    });
});

describe('Service scheduled tasks API', function () {
    test('can list tasks for a service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        ScheduledTask::factory()->create([
            'service_id' => $service->id,
            'team_id' => $this->team->id,
            'name' => 'Service Task',
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/services/{$service->uuid}/scheduled-tasks");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'Service Task']);
    });

    test('can create a task for a service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/services/{$service->uuid}/scheduled-tasks", [
                'name' => 'Service Backup',
                'command' => 'pg_dump',
                'frequency' => '0 2 * * *',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Service Backup']);
    });

    test('can delete a task for a service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $task = ScheduledTask::factory()->create([
            'service_id' => $service->id,
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders(scheduledTaskAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/services/{$service->uuid}/scheduled-tasks/{$task->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Scheduled task deleted.']);
    });
});
