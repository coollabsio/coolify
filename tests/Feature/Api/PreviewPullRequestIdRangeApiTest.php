<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('preview-range', ['*'])->plainTextToken;
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'alpine',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

test('creating a preview rejects a pull request id above the database integer range', function () {
    $this->withToken($this->bearerToken)
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews", [
            'pull_request_id' => 2147483648,
            'docker_tag' => 'pr-1',
            'instant_deploy' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pull_request_id');

    expect($this->application->previews()->count())->toBe(0);
});

test('creating a preview accepts the largest database integer pull request id', function () {
    $this->withToken($this->bearerToken)
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews", [
            'pull_request_id' => 2147483647,
            'docker_tag' => 'pr-1',
            'instant_deploy' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('preview.pull_request_id', 2147483647);
});

test('deploy rejects a pull request id above the database integer range', function (string $field) {
    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/deploy', ['uuid' => $this->application->uuid, $field => '2147483648'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'pull_request_id must be between 1 and 2147483647.');

    Bus::assertNothingDispatched();
})->with(['pr', 'pull_request_id']);

test('deploy passes the largest database integer pull request id to the preview lookup', function () {
    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/deploy', ['uuid' => $this->application->uuid, 'pr' => 2147483647])
        ->assertOk()
        ->assertJsonPath('deployments.0.message', 'Pull request 2147483647 not found for this resource.');
});

test('preview routes reject a pull request id above the database integer range', function (string $method, string $suffix) {
    $this->withToken($this->bearerToken)
        ->json($method, "/api/v1/applications/{$this->application->uuid}/previews/2147483648{$suffix}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid pull_request_id.');
})->with([
    'show' => ['GET', ''],
    'update' => ['PATCH', ''],
    'delete' => ['DELETE', ''],
    'logs' => ['GET', '/logs'],
]);
