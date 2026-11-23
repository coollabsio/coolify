<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['read'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    foreach (range(1, 5) as $number) {
        ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'application_name' => $this->application->name,
            'deployment_uuid' => "deployment-{$number}",
            'server_id' => $server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'pull_request_id' => 0,
        ]);
    }
});

function listApplicationDeployments(array $query): array
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/deployments/applications/'.test()->application->uuid.'?'.http_build_query($query))
        ->assertOk()
        ->json();
}

test('page and per_page select a page of deployments', function () {
    $page = listApplicationDeployments(['page' => 2, 'per_page' => 2]);

    expect($page['count'])->toBe(5)
        ->and(collect($page['deployments'])->pluck('deployment_uuid')->all())
        ->toBe(collect(listApplicationDeployments(['skip' => 2, 'take' => 2])['deployments'])->pluck('deployment_uuid')->all())
        ->toHaveCount(2);
});

test('page and per_page take precedence over skip and take', function () {
    $page = listApplicationDeployments(['page' => 1, 'per_page' => 3, 'skip' => 4, 'take' => 1]);

    expect(collect($page['deployments'])->pluck('deployment_uuid')->all())
        ->toBe(collect(listApplicationDeployments(['skip' => 0, 'take' => 3])['deployments'])->pluck('deployment_uuid')->all());
});

test('page or per_page alone uses the default of the other', function () {
    expect(collect(listApplicationDeployments(['page' => 2, 'per_page' => ''])['deployments']))->toBeEmpty()
        ->and(collect(listApplicationDeployments(['page' => '', 'per_page' => 2])['deployments'])->pluck('deployment_uuid')->all())
        ->toBe(collect(listApplicationDeployments(['skip' => 0, 'take' => 2])['deployments'])->pluck('deployment_uuid')->all())
        ->and(collect(listApplicationDeployments(['page' => 1, 'per_page' => ''])['deployments']))->toHaveCount(5);
});

test('per_page of 100 is accepted', function () {
    expect(listApplicationDeployments(['per_page' => 100])['deployments'])->toHaveCount(5);
});

test('invalid pagination values are rejected', function (array $query, string $field) {
    test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/deployments/applications/'.test()->application->uuid.'?'.http_build_query($query))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'per_page above 100' => [['per_page' => 101], 'per_page'],
    'per_page of 0' => [['per_page' => 0], 'per_page'],
    'page of 0' => [['page' => 0], 'page'],
    'page that is not an integer' => [['page' => 'abc'], 'page'],
    'page with an offset above the integer range' => [['page' => PHP_INT_MAX, 'per_page' => 100], 'page'],
]);

test('invalid skip and take are ignored when page and per_page are given', function () {
    expect(listApplicationDeployments(['page' => 2, 'per_page' => 3, 'take' => 0, 'skip' => -1])['deployments'])->toHaveCount(2);
});

test('take of zero is rejected without page and per_page', function () {
    test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/deployments/applications/'.test()->application->uuid.'?take=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['take']);
});
