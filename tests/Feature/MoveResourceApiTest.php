<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();

    $this->targetProject = Project::factory()->create(['team_id' => $this->team->id]);
    $this->targetEnvironment = $this->targetProject->environments()->first();
});

describe('POST /api/v1/applications/{uuid}/move', function () {
    test('moves application to another environment', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Application moved successfully.']);
        $response->assertJsonStructure(['message', 'uuid', 'project_uuid', 'environment_uuid']);

        $application->refresh();
        expect($application->environment_id)->toBe($this->targetEnvironment->id);
    });

    test('writes audit log when application is moved', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->once()
            ->with('api.application.moved', Mockery::on(function (array $context) use ($application) {
                return $context['event'] === 'api.application.moved'
                    && $context['resource_uuid'] === $application->uuid
                    && $context['resource_type'] === 'application'
                    && $context['from_environment_uuid'] === $this->environment->uuid
                    && $context['to_environment_uuid'] === $this->targetEnvironment->uuid
                    && $context['from_project_uuid'] === $this->project->uuid
                    && $context['to_project_uuid'] === $this->targetProject->uuid;
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();

        $this->actingAs($this->user);

        $request = Request::create('/', 'POST', [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response = moveResourceToEnvironment($request, $application, 'Application', $this->team->id);

        expect($response->getStatusCode())->toBe(200);
    });

    test('returns 404 when application not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/applications/non-existent-uuid/move', [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(404);
    });

    test('returns 422 when environment_uuid is missing', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", []);

        $response->assertStatus(422);
    });

    test('returns 422 when extra fields are provided', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", [
            'environment_uuid' => $this->targetEnvironment->uuid,
            'bogus_field' => 'value',
        ]);

        $response->assertStatus(422);
    });

    test('returns 404 when target environment belongs to another team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", [
            'environment_uuid' => $otherEnvironment->uuid,
        ]);

        $response->assertStatus(404);
    });

    test('authorizes the target environment before moving', function () {
        $this->actingAs($this->user);

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        Gate::before(function (User $user, string $ability, array $arguments) {
            $target = $arguments[0] ?? null;

            if ($ability === 'update' && $target instanceof Environment && $target->is($this->targetEnvironment)) {
                return false;
            }

            return null;
        });

        $request = Request::create('/', 'POST', [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        expect(fn () => moveResourceToEnvironment($request, $application, 'Application', $this->team->id))
            ->toThrow(AuthorizationException::class);

        $application->refresh();
        expect($application->environment_id)->toBe($this->environment->id);
    });

    test('returns 400 when application is already in the target environment', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", [
            'environment_uuid' => $this->environment->uuid,
        ]);

        $response->assertStatus(400);
    });

    test('preserves resource-level environment variables after move', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'TEST_VAR',
            'value' => 'test-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$application->uuid}/move", [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(200);

        $application->refresh();
        $envVar = $application->environment_variables->where('key', 'TEST_VAR')->first();
        expect($envVar)->not->toBeNull();
        expect($envVar->value)->toBe('test-value');
    });
});

describe('POST /api/v1/databases/{uuid}/move', function () {
    test('moves database to another environment', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-pg',
            'postgres_user' => 'postgres',
            'postgres_password' => 'secret',
            'postgres_db' => 'testdb',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$database->uuid}/move", [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Database moved successfully.']);

        $database->refresh();
        expect($database->environment_id)->toBe($this->targetEnvironment->id);
    });

    test('returns 404 when database not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/databases/non-existent-uuid/move', [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/services/{uuid}/move', function () {
    test('moves service to another environment', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/services/{$service->uuid}/move", [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Service moved successfully.']);

        $service->refresh();
        expect($service->environment_id)->toBe($this->targetEnvironment->id);
    });

    test('returns 404 when service not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/services/non-existent-uuid/move', [
            'environment_uuid' => $this->targetEnvironment->uuid,
        ]);

        $response->assertStatus(404);
    });
});
