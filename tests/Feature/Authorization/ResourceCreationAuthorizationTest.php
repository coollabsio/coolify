<?php

use App\Http\Middleware\CanCreateResources;
use App\Livewire\Project\New\DockerCompose;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);

    InstanceSettings::query()->forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });
});

test('member cannot pass create resources middleware', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $middleware = new CanCreateResources;
    $request = Request::create('/project/new', 'GET');

    expect(fn () => $middleware->handle($request, fn () => response('ok')))
        ->toThrow(HttpException::class, 'You do not have permission to create resources.');
});

test('admin can pass create resources middleware', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $middleware = new CanCreateResources;
    $request = Request::create('/project/new', 'GET');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('member cannot create docker compose service through livewire action', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(DockerCompose::class)
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
        ->set('query', ['destination' => $this->destination->uuid])
        ->set('dockerComposeRaw', <<<'YAML'
services:
  app:
    image: alpine
YAML)
        ->call('submit')
        ->assertDispatched('error');

    expect(Service::query()->count())->toBe(0);
});

test('public git docker compose creates an application in local mode', function () {
    config(['app.env' => 'local']);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(PublicGitRepository::class)
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
        ->set('query', ['destination' => $this->destination->uuid])
        ->set('repository_url', 'https://github.com/coollabsio/coolify')
        ->set('git_repository', 'https://github.com/coollabsio/coolify')
        ->set('git_branch', 'main')
        ->set('build_pack', 'dockercompose')
        ->set('new_compose_services', true)
        ->call('submit');

    expect(Application::query()->count())->toBe(1)
        ->and(Service::query()->count())->toBe(0);
});
