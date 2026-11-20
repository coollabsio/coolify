<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['read'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->otherDestination = StandaloneDocker::query()->where('server_id', $this->otherServer->id)->firstOrFail();

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
    $this->stagingEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->otherProject = Project::factory()->create(['team_id' => $this->team->id]);

    $makeApp = fn (string $name, string $status, Environment $environment, StandaloneDocker $destination) => Application::factory()->create([
        'name' => $name,
        'status' => $status,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->api = $makeApp('Api Backend', 'running:healthy', $this->environment, $this->destination);
    $this->web = $makeApp('Web Frontend', 'exited', $this->stagingEnvironment, $this->destination);
    $this->worker = $makeApp('Worker', 'running:unknown', $this->otherProject->environments()->first(), $this->otherDestination);
});

function listApplications(array $query = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/applications'.($query ? '?'.http_build_query($query) : ''));
}

function listedNames(TestResponse $response): array
{
    return collect($response->json())->pluck('name')->all();
}

test('without parameters it returns all team applications as a plain array without a total header', function () {
    $response = listApplications()->assertOk();

    expect(listedNames($response))->toBe(['Api Backend', 'Web Frontend', 'Worker'])
        ->and($response->headers->has('X-Total-Count'))->toBeFalse();
});

test('it filters by tag', function () {
    $tag = Tag::create(['name' => 'backend', 'team_id' => $this->team->id]);
    $this->api->tags()->attach($tag->id);

    expect(listedNames(listApplications(['tag' => 'backend'])->assertOk()))->toBe(['Api Backend']);
});

test('it filters by project uuid', function () {
    expect(listedNames(listApplications(['project_uuid' => $this->project->uuid])->assertOk()))
        ->toBe(['Api Backend', 'Web Frontend']);
});

test('it filters by environment uuid', function () {
    expect(listedNames(listApplications(['environment_uuid' => $this->stagingEnvironment->uuid])->assertOk()))
        ->toBe(['Web Frontend']);
});

test('it filters by server uuid', function () {
    expect(listedNames(listApplications(['server_uuid' => $this->otherServer->uuid])->assertOk()))
        ->toBe(['Worker']);
});

test('it filters by status and name case-insensitively', function () {
    expect(listedNames(listApplications(['status' => 'RUNNING'])->assertOk()))->toBe(['Api Backend', 'Worker'])
        ->and(listedNames(listApplications(['name' => 'front'])->assertOk()))->toBe(['Web Frontend'])
        ->and(listedNames(listApplications(['status' => 'running', 'project_uuid' => $this->project->uuid])->assertOk()))->toBe(['Api Backend']);
});

test('unknown uuids give an empty list', function (string $parameter) {
    listApplications([$parameter => 'does-not-exist'])->assertOk()->assertExactJson([]);
})->with(['project_uuid', 'environment_uuid', 'server_uuid']);

test('uuids of another team give an empty list', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->first();
    Application::factory()->create([
        'name' => 'Foreign App',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    listApplications(['project_uuid' => $otherProject->uuid])->assertOk()->assertExactJson([]);
    listApplications(['environment_uuid' => $otherEnvironment->uuid])->assertOk()->assertExactJson([]);
    listApplications(['server_uuid' => $otherServer->uuid])->assertOk()->assertExactJson([]);
    expect(listedNames(listApplications()))->not->toContain('Foreign App');
});

test('it paginates with a total count header', function () {
    $first = listApplications(['per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');
    $second = listApplications(['page' => 2, 'per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');
    $beyond = listApplications(['page' => 3, 'per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');

    expect(listedNames($first))->toBe(['Api Backend', 'Web Frontend'])
        ->and(listedNames($second))->toBe(['Worker'])
        ->and($beyond->json())->toBe([]);
});

test('the total count header counts only the filtered applications', function () {
    listApplications(['page' => 1, 'project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertHeader('X-Total-Count', '2')
        ->assertJsonCount(2);
});

test('it rejects invalid pagination parameters', function (array $query, string $field) {
    listApplications($query)->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'page zero' => [['page' => 0], 'page'],
    'per_page too large' => [['per_page' => 101], 'per_page'],
    'per_page not an integer' => [['per_page' => 'abc'], 'per_page'],
]);
