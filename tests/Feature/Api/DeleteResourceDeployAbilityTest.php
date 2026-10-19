<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    Queue::fake();
    Bus::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $destinationAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $this->resources = [
        'applications' => Application::factory()->create($destinationAttributes),
        'services' => Service::factory()->create($destinationAttributes + ['server_id' => $server->id]),
        'databases' => StandalonePostgresql::create($destinationAttributes + [
            'name' => 'delete-db',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
        ]),
    ];

    $this->preview = ApplicationPreview::create([
        'uuid' => (string) new Cuid2,
        'application_id' => $this->resources['applications']->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'fqdn' => 'pr-42.example.com',
    ]);

    CleanupPreviewDeployment::shouldRun()->andReturn([
        'cancelled_deployments' => 0,
        'killed_containers' => 0,
        'status' => 'success',
    ]);
});

function deleteAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'delete-resource-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

test('write-only token cannot delete a resource', function (string $type) {
    $resource = $this->resources[$type];

    $this->withHeaders(deleteAbilityHeaders($this->user, $this->team->id, ['write']))
        ->deleteJson("/api/v1/{$type}/{$resource->uuid}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy');

    expect($resource::query()->whereKey($resource->getKey())->exists())->toBeTrue();
    Queue::assertNotPushed(DeleteResourceJob::class);
})->with(['applications', 'services', 'databases']);

test('write and deploy token deletes a resource', function (string $type) {
    $resource = $this->resources[$type];

    $this->withHeaders(deleteAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->deleteJson("/api/v1/{$type}/{$resource->uuid}")
        ->assertOk();

    expect($resource::query()->whereKey($resource->getKey())->exists())->toBeFalse();
})->with(['applications', 'services', 'databases']);

test('write-only token cannot delete a preview deployment', function () {
    $application = $this->resources['applications'];

    $this->withHeaders(deleteAbilityHeaders($this->user, $this->team->id, ['write']))
        ->deleteJson("/api/v1/applications/{$application->uuid}/previews/42")
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy');

    expect($this->preview->fresh()->trashed())->toBeFalse();
});

test('write and deploy token deletes a preview deployment', function () {
    $application = $this->resources['applications'];

    $this->withHeaders(deleteAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->deleteJson("/api/v1/applications/{$application->uuid}/previews/42")
        ->assertOk();

    expect($this->preview->fresh()->trashed())->toBeTrue();
});
