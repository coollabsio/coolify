<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'main-server', 'ip' => '203.0.113.10']);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'canvas-env']);
});

function environmentCanvasActingAs(User $user, Team $team): mixed
{
    return test()->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

function environmentCanvasUrl(Project $project, Environment $environment, string $suffix = ''): string
{
    return "/canvas/api/projects/{$project->uuid}/environments/{$environment->uuid}/canvas{$suffix}";
}

function environmentCanvasApplication(Environment $environment, StandaloneDocker $destination, array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        ...$attributes,
    ]);
}

function environmentCanvasPostgres(Environment $environment, StandaloneDocker $destination): StandalonePostgresql
{
    return create_standalone_postgresql($environment->id, $destination, [
        'name' => 'main-db',
        'postgres_password' => 'canvas-secret-password',
    ], 'postgres:17-alpine');
}

describe('GET canvas', function () {
    test('returns zones, nodes, links and positions of the environment', function () {
        $extraServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'extra-server']);
        $extraDestination = StandaloneDocker::query()->where('server_id', $extraServer->id)->firstOrFail();

        $application = environmentCanvasApplication($this->environment, $this->destination, [
            'name' => 'web',
            'git_branch' => 'main',
            'fqdn' => 'https://web.example.com, https://www.example.com,',
            'status' => 'running:healthy',
        ]);
        $application->additional_servers()->attach($extraServer->id, ['standalone_docker_id' => $extraDestination->id, 'status' => 'running:healthy']);
        LocalPersistentVolume::create(['name' => 'web-data', 'mount_path' => '/data', 'resource_type' => $application->getMorphClass(), 'resource_id' => $application->id]);

        $service = Service::factory()->create([
            'name' => 'stack',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        ServiceApplication::forceCreate(['name' => 'ghost', 'service_id' => $service->id, 'fqdn' => 'https://ghost.example.com', 'status' => 'running:healthy']);
        ServiceDatabase::forceCreate(['name' => 'mysql', 'service_id' => $service->id, 'status' => 'running:healthy']);

        $database = environmentCanvasPostgres($this->environment, $this->destination);

        $link = ResourceLink::create([
            'environment_id' => $this->environment->id,
            'source_type' => $application->getMorphClass(),
            'source_id' => $application->id,
            'target_type' => $database->getMorphClass(),
            'target_id' => $database->id,
            'label' => 'DATABASE_URL',
        ]);
        $this->environment->forceFill(['canvas_positions' => [$application->uuid => ['x' => 10, 'y' => -20], 'gone-resource' => ['x' => 1, 'y' => 1]]])->save();

        environmentCanvasActingAs($this->admin, $this->team);
        $response = $this
            ->getJson(environmentCanvasUrl($this->project, $this->environment))
            ->assertOk();

        $response->assertJsonPath('project', ['uuid' => $this->project->uuid, 'name' => $this->project->name, 'link' => route('project.show', ['project_uuid' => $this->project->uuid])])
            ->assertJsonPath('environment', ['uuid' => $this->environment->uuid, 'name' => 'canvas-env', 'link' => route('project.resource.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])])
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('links', [['id' => $link->uuid, 'source' => $application->uuid, 'target' => $database->uuid, 'label' => 'DATABASE_URL']])
            ->assertJsonPath('positions', [$application->uuid => ['x' => 10, 'y' => -20]]);

        expect(collect($response->json('zones'))->keyBy('id')->all())->toEqual([
            $this->server->uuid => ['id' => $this->server->uuid, 'name' => 'main-server', 'ip' => '203.0.113.10', 'is_reachable' => false, 'is_usable' => false, 'link' => route('server.show', ['server_uuid' => $this->server->uuid])],
            $extraServer->uuid => ['id' => $extraServer->uuid, 'name' => 'extra-server', 'ip' => $extraServer->ip, 'is_reachable' => false, 'is_usable' => false, 'link' => route('server.show', ['server_uuid' => $extraServer->uuid])],
        ]);

        $nodes = collect($response->json('nodes'))->keyBy('id');
        expect($nodes)->toHaveCount(3)
            ->and($nodes[$application->uuid])->toEqual([
                'id' => $application->uuid,
                'type' => 'application',
                'name' => 'web',
                'status' => 'running:healthy',
                'zone' => $this->server->uuid,
                'extra_zones' => [$extraServer->uuid],
                'urls' => ['https://web.example.com', 'https://www.example.com'],
                'kind' => 'nixpacks',
                'subtitle' => 'Nixpacks · main',
                'children' => [],
                'link' => $application->link(),
                'logo' => ['src' => resource_logo_url('public-repo'), 'dark' => null, 'fallback' => null, 'padded' => false, 'favicon' => 'https://web.example.com/favicon.ico'],
                'saved' => ['x' => 10, 'y' => -20],
            ])
            ->and($nodes[$service->uuid])->toEqual([
                'id' => $service->uuid,
                'type' => 'service',
                'name' => 'stack',
                'status' => 'running:healthy',
                'zone' => $this->server->uuid,
                'extra_zones' => [],
                'urls' => ['https://ghost.example.com'],
                'kind' => null,
                'subtitle' => 'Service · 2 containers',
                'children' => [
                    ['name' => 'ghost', 'type' => 'application', 'status' => 'running:healthy'],
                    ['name' => 'mysql', 'type' => 'database', 'status' => 'running:healthy'],
                ],
                'link' => $service->link(),
                'logo' => null,
                'saved' => null,
            ])
            ->and($nodes[$database->uuid])->toMatchArray([
                'type' => 'database',
                'name' => 'main-db',
                'zone' => $this->server->uuid,
                'urls' => [],
                'kind' => 'postgresql',
                'subtitle' => 'PostgreSQL · postgres:17-alpine',
                'logo' => ['src' => resource_logo_url('postgres'), 'dark' => null, 'fallback' => null, 'padded' => false, 'favicon' => null],
                'saved' => null,
            ])
            ->and($response->getContent())->not->toContain('canvas-secret-password');
    });

    test('drops links whose resource was deleted', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);
        $database = environmentCanvasPostgres($this->environment, $this->destination);
        ResourceLink::create([
            'environment_id' => $this->environment->id,
            'source_type' => $application->getMorphClass(),
            'source_id' => $application->id,
            'target_type' => $database->getMorphClass(),
            'target_id' => $database->id,
        ]);
        $database->forceDelete();

        environmentCanvasActingAs($this->admin, $this->team)
            ->getJson(environmentCanvasUrl($this->project, $this->environment))
            ->assertOk()
            ->assertJsonPath('links', []);
    });

    test('hides server ips and editing from a member', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        environmentCanvasApplication($this->environment, $this->destination);

        environmentCanvasActingAs($member, $this->team)
            ->getJson(environmentCanvasUrl($this->project, $this->environment))
            ->assertOk()
            ->assertJsonPath('zones.0.ip', null)
            ->assertJsonPath('can_edit', false);
    });

    test('finds the environment by name', function () {
        environmentCanvasActingAs($this->admin, $this->team)
            ->getJson("/canvas/api/projects/{$this->project->uuid}/environments/canvas-env/canvas")
            ->assertOk()
            ->assertJsonPath('environment.uuid', $this->environment->uuid);
    });

    test('does not run more queries for more applications', function () {
        environmentCanvasActingAs($this->admin, $this->team);
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this
                ->getJson(environmentCanvasUrl($this->project, $this->environment))
                ->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->reject(fn (array $query) => str_contains($query['query'], 'audit_events'))->count();
        };
        environmentCanvasApplication($this->environment, $this->destination, ['fqdn' => 'https://a.example.com']);
        $countQueries();
        $queriesForOne = $countQueries();

        foreach (range(1, 4) as $index) {
            environmentCanvasApplication($this->environment, $this->destination, ['fqdn' => "https://a{$index}.example.com"]);
        }
        $queriesForFive = $countQueries();

        expect($queriesForFive)->toBe($queriesForOne);
    });
});

describe('PATCH canvas positions', function () {
    test('merges positions, ignores unknown resources and prunes removed ones', function () {
        $first = environmentCanvasApplication($this->environment, $this->destination);
        $second = environmentCanvasApplication($this->environment, $this->destination);
        $this->environment->forceFill(['canvas_positions' => [
            $first->uuid => ['x' => 1, 'y' => 2],
            'removed-resource' => ['x' => 5, 'y' => 5],
        ]])->save();
        $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
        $foreign = environmentCanvasApplication($otherEnvironment, $this->destination);

        environmentCanvasActingAs($this->admin, $this->team)
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, '/positions'), ['positions' => [
                $second->uuid => ['x' => -300, 'y' => 400],
                $foreign->uuid => ['x' => 9, 'y' => 9],
                'unknown' => ['x' => 'not-a-number'],
            ]])
            ->assertOk()
            ->assertExactJson(['positions' => [
                $first->uuid => ['x' => 1, 'y' => 2],
                $second->uuid => ['x' => -300, 'y' => 400],
            ]]);

        expect($this->environment->refresh()->canvas_positions)->toBe([
            $first->uuid => ['x' => 1, 'y' => 2],
            $second->uuid => ['x' => -300, 'y' => 400],
        ]);
    });

    test('rejects coordinates out of range', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);

        environmentCanvasActingAs($this->admin, $this->team)
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, '/positions'), ['positions' => [
                $application->uuid => ['x' => 100001, 'y' => 0],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["positions.{$application->uuid}.x"]);
    });
});

describe('canvas links', function () {
    test('creates, relabels and deletes a link', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);
        $database = environmentCanvasPostgres($this->environment, $this->destination);
        environmentCanvasActingAs($this->admin, $this->team);

        $response = $this
            ->postJson(environmentCanvasUrl($this->project, $this->environment, '/links'), [
                'source' => $application->uuid,
                'target' => $database->uuid,
                'label' => 'uses',
            ])
            ->assertCreated()
            ->assertJson(['source' => $application->uuid, 'target' => $database->uuid, 'label' => 'uses']);
        $linkUuid = $response->json('id');
        expect(ResourceLink::query()->whereUuid($linkUuid)->firstOrFail()->created_by)->toBe($this->admin->id);

        $this
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, "/links/{$linkUuid}"), ['label' => 'reads'])
            ->assertOk()
            ->assertExactJson(['id' => $linkUuid, 'source' => $application->uuid, 'target' => $database->uuid, 'label' => 'reads']);

        $this
            ->deleteJson(environmentCanvasUrl($this->project, $this->environment, "/links/{$linkUuid}"))
            ->assertOk()
            ->assertJson(['message' => 'Link deleted.']);

        expect(ResourceLink::query()->count())->toBe(0);
    });

    test('rejects a link to itself', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);

        environmentCanvasActingAs($this->admin, $this->team)
            ->postJson(environmentCanvasUrl($this->project, $this->environment, '/links'), [
                'source' => $application->uuid,
                'target' => $application->uuid,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target']);
    });

    test('rejects a resource of another environment', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);
        $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
        $foreign = environmentCanvasApplication($otherEnvironment, $this->destination);

        environmentCanvasActingAs($this->admin, $this->team)
            ->postJson(environmentCanvasUrl($this->project, $this->environment, '/links'), [
                'source' => $application->uuid,
                'target' => $foreign->uuid,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target']);

        expect(ResourceLink::query()->count())->toBe(0);
    });

    test('rejects a duplicate link but allows the opposite direction', function () {
        $application = environmentCanvasApplication($this->environment, $this->destination);
        $database = environmentCanvasPostgres($this->environment, $this->destination);
        environmentCanvasActingAs($this->admin, $this->team);
        $url = environmentCanvasUrl($this->project, $this->environment, '/links');

        $this->postJson($url, ['source' => $application->uuid, 'target' => $database->uuid])->assertCreated();
        $this->postJson($url, ['source' => $application->uuid, 'target' => $database->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target']);
        $this->postJson($url, ['source' => $database->uuid, 'target' => $application->uuid])->assertCreated();
    });

    test('returns 404 for a link of another environment', function () {
        $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
        $first = environmentCanvasApplication($otherEnvironment, $this->destination);
        $second = environmentCanvasApplication($otherEnvironment, $this->destination);
        $link = ResourceLink::create([
            'environment_id' => $otherEnvironment->id,
            'source_type' => $first->getMorphClass(),
            'source_id' => $first->id,
            'target_type' => $second->getMorphClass(),
            'target_id' => $second->id,
        ]);
        environmentCanvasActingAs($this->admin, $this->team);

        $this
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, "/links/{$link->uuid}"), ['label' => 'x'])
            ->assertNotFound();
        $this
            ->deleteJson(environmentCanvasUrl($this->project, $this->environment, "/links/{$link->uuid}"))
            ->assertNotFound();

        expect($link->fresh())->not->toBeNull();
    });
});

describe('canvas authorization', function () {
    test('a member can read the canvas but cannot change it', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $application = environmentCanvasApplication($this->environment, $this->destination);
        $database = environmentCanvasPostgres($this->environment, $this->destination);
        environmentCanvasActingAs($member, $this->team);

        $this->getJson(environmentCanvasUrl($this->project, $this->environment))->assertOk();
        $this
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, '/positions'), ['positions' => [$application->uuid => ['x' => 1, 'y' => 1]]])
            ->assertForbidden();
        $this
            ->postJson(environmentCanvasUrl($this->project, $this->environment, '/links'), ['source' => $application->uuid, 'target' => $database->uuid])
            ->assertForbidden();

        expect($this->environment->refresh()->canvas_positions)->toBeNull()
            ->and(ResourceLink::query()->count())->toBe(0);
    });

    test('returns 404 for a project of another team', function () {
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        $application = environmentCanvasApplication($this->environment, $this->destination);
        environmentCanvasActingAs($otherUser, $otherTeam);

        $this->getJson(environmentCanvasUrl($this->project, $this->environment))->assertNotFound();
        $this
            ->patchJson(environmentCanvasUrl($this->project, $this->environment, '/positions'), ['positions' => [$application->uuid => ['x' => 1, 'y' => 1]]])
            ->assertNotFound();
    });
    test('is not part of the public API', function () {
        $plainTextToken = 'environment-canvas-test-token';
        $token = $this->admin->tokens()->create([
            'name' => 'environment-canvas-test',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['root'],
            'team_id' => $this->team->id,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token->getKey()}|{$plainTextToken}"])
            ->getJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}/canvas")
            ->assertNotFound();
    });
});

describe('canvas page', function () {
    test('add resource opens the deploy canvas for this environment', function () {
        $deployCanvasUrl = route('deploy-canvas', ['project' => $this->project->uuid, 'environment' => $this->environment->uuid]);

        $this->actingAs($this->admin)->withSession(['currentTeam' => $this->team])
            ->withoutVite()
            ->get(route('project.environment.canvas', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]))
            ->assertOk()
            ->assertSee('data-new="'.e($deployCanvasUrl).'"', false)
            ->assertSee('href="'.e($deployCanvasUrl).'"', false)
            ->assertDontSee(route('project.resource.create', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]), false);
    });

    test('a team member can open the canvas page', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)->withSession(['currentTeam' => $this->team])
            ->withoutVite()
            ->get(route('project.environment.canvas', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]))
            ->assertOk()
            ->assertSee('data-environment-canvas', false)
            ->assertSee('data-environment="'.$this->environment->uuid.'"', false);
    });

    test('another team gets a 404', function () {
        $otherTeam = Team::factory()->create(['show_boarding' => false]);
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

        $this->actingAs($otherUser)->withSession(['currentTeam' => $otherTeam])
            ->withoutVite()
            ->get(route('project.environment.canvas', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]))
            ->assertNotFound();
    });
});
