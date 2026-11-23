<?php

use App\Enums\ProcessStatus;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

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

    $makeService = fn (string $name, Environment $environment, StandaloneDocker $destination) => Service::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'server_id' => $destination->server_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->ghost = $makeService('Ghost Blog', $this->environment, $this->destination);
    $this->plausible = $makeService('Plausible', $this->stagingEnvironment, $this->destination);
    $this->umami = $makeService('Umami', $this->otherProject->environments()->first(), $this->otherDestination);

    foreach ([$this->ghost, $this->umami] as $service) {
        ServiceApplication::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'web',
            'service_id' => $service->id,
            'image' => 'nginx:alpine',
            'status' => 'running:healthy',
        ]);
    }
});

function listServices(array $query = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/services'.($query ? '?'.http_build_query($query) : ''));
}

function listedServiceNames(TestResponse $response): array
{
    return collect($response->json())->pluck('name')->all();
}

test('without parameters it returns all team services without a total header', function () {
    $response = listServices()->assertOk();

    expect(listedServiceNames($response))->toBe(['Ghost Blog', 'Plausible', 'Umami'])
        ->and($response->headers->has('X-Total-Count'))->toBeFalse();
});

test('it filters by project, environment, server uuid, and name', function () {
    expect(listedServiceNames(listServices(['project_uuid' => $this->project->uuid])->assertOk()))->toBe(['Ghost Blog', 'Plausible'])
        ->and(listedServiceNames(listServices(['environment_uuid' => $this->stagingEnvironment->uuid])->assertOk()))->toBe(['Plausible'])
        ->and(listedServiceNames(listServices(['server_uuid' => $this->otherServer->uuid])->assertOk()))->toBe(['Umami'])
        ->and(listedServiceNames(listServices(['name' => 'GHOST'])->assertOk()))->toBe(['Ghost Blog']);
});

test('it filters by computed status and returns the same items as the unfiltered list', function () {
    $response = listServices(['status' => 'running'])->assertOk();

    expect(listedServiceNames($response))->toBe(['Ghost Blog', 'Umami'])
        ->and($response->json('0'))->toBe(listServices()->json('0'));

    $page = listServices(['status' => 'running', 'page' => 2, 'per_page' => 1])
        ->assertOk()
        ->assertHeader('X-Total-Count', '2');

    expect(listedServiceNames($page))->toBe(['Umami']);
});

test('unknown uuids and uuids of another team give an empty list', function () {
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $foreignDestination = StandaloneDocker::query()->where('server_id', $foreignServer->id)->firstOrFail();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    Service::factory()->create([
        'name' => 'Foreign Service',
        'environment_id' => $foreignProject->environments()->first()->id,
        'server_id' => $foreignServer->id,
        'destination_id' => $foreignDestination->id,
        'destination_type' => $foreignDestination->getMorphClass(),
    ]);

    listServices(['server_uuid' => 'does-not-exist'])->assertOk()->assertExactJson([]);
    listServices(['project_uuid' => $foreignProject->uuid])->assertOk()->assertExactJson([]);
    listServices(['server_uuid' => $foreignServer->uuid])->assertOk()->assertExactJson([]);
    expect(listedServiceNames(listServices()))->not->toContain('Foreign Service');
});

test('it paginates with a total count header', function () {
    $first = listServices(['per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');
    $second = listServices(['page' => 2, 'per_page' => 2])->assertOk()->assertHeader('X-Total-Count', '3');

    expect(listedServiceNames($first))->toBe(['Ghost Blog', 'Plausible'])
        ->and(listedServiceNames($second))->toBe(['Umami']);

    listServices(['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
});

test('it rejects a page whose offset does not fit in an integer and keeps pages after the last result empty', function () {
    listServices(['status' => 'running', 'page' => intdiv(PHP_INT_MAX, 2) + 2, 'per_page' => 2])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['page']);

    listServices(['status' => 'running', 'page' => 50, 'per_page' => 2])
        ->assertOk()
        ->assertHeader('X-Total-Count', '2')
        ->assertExactJson([]);
});

test('the status filter skips components removed from compose', function () {
    $this->umami->applications()->update(['is_removed_from_compose' => true]);

    expect(listedServiceNames(listServices(['status' => 'running'])->assertOk()))->toBe(['Ghost Blog']);
});

test('the status filter returns the status that admitted each service', function () {
    $query = Service::whereRelation('environment.project', 'team_id', $this->team->id);

    [$total, $services] = Service::pageByStatus($query, 'running');
    ServiceApplication::query()->update(['status' => 'exited']);
    $services->load(['applications', 'databases']);

    expect($total)->toBe(2)
        ->and($services->map(fn (Service $service) => $service->toArray()['status'])->all())
        ->toBe(['running:healthy', 'running:healthy']);
});

test('the status filter reads the start activities of each chunk in one query and ignores stale starts', function () {
    $logStart = function (Service $service, int $minutesAgo) {
        $activity = activity()
            ->withProperties(['type_uuid' => $service->uuid, 'status' => ProcessStatus::IN_PROGRESS->value])
            ->log('[]');
        Activity::query()->whereKey($activity->id)->update([
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    };
    $logStart($this->ghost, 1);
    $logStart($this->umami, 60);

    DB::enableQueryLog();
    $response = listServices(['status' => 'starting', 'per_page' => 1])->assertOk();
    $activityQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'activity_log'));

    expect(listedServiceNames($response))->toBe(['Ghost Blog'])
        ->and($response->headers->get('X-Total-Count'))->toBe('1')
        ->and($activityQueries)->toHaveCount(1)
        ->and(listedServiceNames(listServices(['status' => 'running'])->assertOk()))->toBe(['Umami']);
});
