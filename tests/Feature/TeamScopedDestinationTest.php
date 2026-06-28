<?php

use App\Livewire\Destination\Resources as DestinationResources;
use App\Livewire\Destination\Show as DestinationShow;
use App\Livewire\Project\New\DockerCompose;
use App\Livewire\Project\New\DockerImage;
use App\Livewire\Project\New\GithubPrivateRepository;
use App\Livewire\Project\New\GithubPrivateRepositoryDeployKey;
use App\Livewire\Project\New\PublicGitRepository;
use App\Livewire\Project\New\SimpleDockerfile;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->destinationA = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'name' => 'dest-a-'.fake()->unique()->word(),
        'network' => 'coolify-a-'.fake()->unique()->word(),
    ]);

    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);
    $this->destinationB = StandaloneDocker::factory()->create([
        'server_id' => $this->serverB->id,
        'name' => 'dest-b-'.fake()->unique()->word(),
        'network' => 'coolify-b-'.fake()->unique()->word(),
    ]);
    $this->swarmDestinationB = SwarmDocker::create([
        'uuid' => fake()->uuid(),
        'name' => 'swarm-b-'.fake()->unique()->word(),
        'network' => 'swarm-b-'.fake()->unique()->word(),
        'server_id' => $this->serverB->id,
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

describe('find_destination_for_current_team helper', function () {
    test('returns null for other team destination UUID', function () {
        expect(find_destination_for_current_team($this->destinationB->uuid))->toBeNull();
    });

    test('returns null for other team swarm destination UUID', function () {
        expect(find_destination_for_current_team($this->swarmDestinationB->uuid))->toBeNull();
    });

    test('returns own team destination', function () {
        $found = find_destination_for_current_team($this->destinationA->uuid);
        expect($found)->not->toBeNull();
        expect($found->id)->toBe($this->destinationA->id);
    });

    test('returns null for blank uuid', function () {
        expect(find_destination_for_current_team(null))->toBeNull();
        expect(find_destination_for_current_team(''))->toBeNull();
    });
});

describe('SimpleDockerfile destination team scope', function () {
    test('submit with other team destination throws and creates no application', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];
        request()->headers->set('referer', route('project.resource.create', $routeParams).'?destination='.$this->destinationB->uuid);

        $before = Application::count();

        expect(fn () => Livewire::withUrlParams(['destination' => $this->destinationB->uuid])
            ->test(SimpleDockerfile::class, $routeParams)
            ->set('dockerfile', "FROM nginx\nCMD [\"nginx\"]\n")
            ->call('submit'))
            ->toThrow(Exception::class, 'Destination not found.');

        expect(Application::count())->toBe($before);
    });
});

describe('DockerImage destination team scope', function () {
    test('submit with other team destination throws and creates no application', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        $before = Application::count();

        expect(fn () => Livewire::withUrlParams(['destination' => $this->destinationB->uuid])
            ->test(DockerImage::class, $routeParams)
            ->set('imageName', 'nginx')
            ->set('imageTag', 'latest')
            ->call('submit'))
            ->toThrow(Exception::class, 'Destination not found.');

        expect(Application::count())->toBe($before);
    });

    test('submit with other team swarm destination throws', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        expect(fn () => Livewire::withUrlParams(['destination' => $this->swarmDestinationB->uuid])
            ->test(DockerImage::class, $routeParams)
            ->set('imageName', 'nginx')
            ->set('imageTag', 'latest')
            ->call('submit'))
            ->toThrow(Exception::class, 'Destination not found.');
    });
});

describe('DockerCompose destination + server_id team scope', function () {
    test('submit with other team destination throws and creates no service', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        $before = Service::count();

        Livewire::withUrlParams([
            'destination' => $this->destinationB->uuid,
            'server_id' => $this->serverB->id,
        ])
            ->test(DockerCompose::class, $routeParams)
            ->set('dockerComposeRaw', "services:\n  app:\n    image: nginx\n")
            ->call('submit');

        expect(Service::count())->toBe($before);
    });

});

describe('PublicGitRepository destination team scope', function () {
    test('submit with other team destination creates no application', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        $before = Application::count();

        try {
            Livewire::withUrlParams(['destination' => $this->destinationB->uuid])
                ->test(PublicGitRepository::class, $routeParams)
                ->set('repository_url', 'https://github.com/coollabsio/coolify')
                ->set('git_repository', 'coollabsio/coolify')
                ->set('git_branch', 'main')
                ->set('port', 3000)
                ->set('build_pack', 'nixpacks')
                ->set('git_source', 'other')
                ->call('submit');
        } catch (Throwable $e) {
            // submit wraps errors via handleError; count assertion below is source of truth
        }

        expect(Application::count())->toBe($before);
    });
});

describe('GithubPrivateRepository destination team scope', function () {
    test('submit with other team destination throws and creates no application', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        $before = Application::count();

        try {
            Livewire::withUrlParams(['destination' => $this->destinationB->uuid])
                ->test(GithubPrivateRepository::class, $routeParams)
                ->call('submit');
        } catch (Throwable $e) {
            // expected
        }

        expect(Application::count())->toBe($before);
    });
});

describe('GithubPrivateRepositoryDeployKey destination team scope', function () {
    test('submit with other team destination throws and creates no application', function () {
        $routeParams = [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ];

        $before = Application::count();

        try {
            Livewire::withUrlParams(['destination' => $this->destinationB->uuid])
                ->test(GithubPrivateRepositoryDeployKey::class, $routeParams)
                ->call('submit');
        } catch (Throwable $e) {
            // expected
        }

        expect(Application::count())->toBe($before);
    });
});

describe('Resource/Create database destination team scope', function () {
    test('mount with other team destination does not create database', function () {
        $before = StandalonePostgresql::count();

        $url = route('project.resource.create', [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ]).'?type=postgresql&destination='.$this->destinationB->uuid.'&server_id='.$this->serverB->id.'&database_image=postgres:16-alpine';

        $this->get($url);

        expect(StandalonePostgresql::count())->toBe($before);
    });

});

describe('StandaloneDocker/SwarmDocker ownedByCurrentTeam scope', function () {
    test('StandaloneDocker::ownedByCurrentTeam excludes other team destinations', function () {
        expect(StandaloneDocker::ownedByCurrentTeam()->where('uuid', $this->destinationB->uuid)->first())->toBeNull();
    });

    test('SwarmDocker::ownedByCurrentTeam excludes other team destinations', function () {
        expect(SwarmDocker::ownedByCurrentTeam()->where('uuid', $this->swarmDestinationB->uuid)->first())->toBeNull();
    });

    test('StandaloneDocker::ownedByCurrentTeam returns own destination', function () {
        $found = StandaloneDocker::ownedByCurrentTeam()->where('uuid', $this->destinationA->uuid)->first();
        expect($found)->not->toBeNull();
        expect($found->id)->toBe($this->destinationA->id);
    });

    test('StandaloneDocker::ownedByCurrentTeamAPI scopes by explicit team id', function () {
        expect(StandaloneDocker::ownedByCurrentTeamAPI($this->teamA->id)->where('uuid', $this->destinationB->uuid)->first())->toBeNull();
        expect(StandaloneDocker::ownedByCurrentTeamAPI($this->teamB->id)->where('uuid', $this->destinationB->uuid)->first()?->id)->toBe($this->destinationB->id);
    });

    test('SwarmDocker::ownedByCurrentTeamAPI scopes by explicit team id', function () {
        expect(SwarmDocker::ownedByCurrentTeamAPI($this->teamA->id)->where('uuid', $this->swarmDestinationB->uuid)->first())->toBeNull();
        expect(SwarmDocker::ownedByCurrentTeamAPI($this->teamB->id)->where('uuid', $this->swarmDestinationB->uuid)->first()?->id)->toBe($this->swarmDestinationB->id);
    });
});

describe('Destination/Show team scope', function () {
    test('mount with other team destination UUID redirects to index', function () {
        $component = Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destinationB->uuid]);

        expect($component->get('destination'))->toBeNull();
        $component->assertRedirect(route('destination.index'));
    });

    test('mount with own destination UUID loads it', function () {
        $component = Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destinationA->uuid]);

        expect($component->get('destination'))->not->toBeNull();
        expect($component->get('destination')->id)->toBe($this->destinationA->id);
    });

    test('mount with other team swarm destination UUID redirects to index', function () {
        $component = Livewire::test(DestinationShow::class, ['destination_uuid' => $this->swarmDestinationB->uuid]);

        expect($component->get('destination'))->toBeNull();
        $component->assertRedirect(route('destination.index'));
    });

    test('general page links to separate resources page without rendering the resources table', function () {
        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destinationA->uuid])
            ->assertSee('General')
            ->assertSee('Resources')
            ->assertDontSee('Search resources...')
            ->assertDontSee('No resources are using this destination.');
    });

    test('mount with own standalone destination lists deployed resources', function () {
        Application::factory()->create([
            'name' => 'application-on-destination',
            'environment_id' => $this->environmentA->id,
            'destination_id' => $this->destinationA->id,
            'destination_type' => StandaloneDocker::class,
        ]);
        Service::factory()->create([
            'name' => 'service-on-destination',
            'environment_id' => $this->environmentA->id,
            'destination_id' => $this->destinationA->id,
            'destination_type' => StandaloneDocker::class,
        ]);
        StandalonePostgresql::withoutEvents(fn () => StandalonePostgresql::create([
            'uuid' => fake()->uuid(),
            'name' => 'database-on-destination',
            'postgres_password' => 'password',
            'environment_id' => $this->environmentA->id,
            'destination_id' => $this->destinationA->id,
            'destination_type' => StandaloneDocker::class,
        ]));

        Livewire::test(DestinationResources::class, ['destination_uuid' => $this->destinationA->uuid])
            ->assertSee('Search resources...')
            ->assertSee('Project')
            ->assertSee('Environment')
            ->assertSee('Name')
            ->assertSee('Type')
            ->assertSee('application-on-destination')
            ->assertSee('service-on-destination')
            ->assertSee('database-on-destination')
            ->assertSee($this->projectA->name)
            ->assertSee($this->environmentA->name);
    });

    test('mount with own standalone destination shows empty state without resources', function () {
        Livewire::test(DestinationResources::class, ['destination_uuid' => $this->destinationA->uuid])
            ->assertSee('No resources are using this destination.');
    });

    test('mount with own standalone destination does not list another team resources', function () {
        Application::factory()->create([
            'name' => 'other-team-application',
            'environment_id' => $this->environmentB->id,
            'destination_id' => $this->destinationB->id,
            'destination_type' => StandaloneDocker::class,
        ]);

        Livewire::test(DestinationResources::class, ['destination_uuid' => $this->destinationA->uuid])
            ->assertDontSee('other-team-application');
    });

    test('resource without project renders as non-clickable row', function () {
        StandalonePostgresql::withoutEvents(fn () => StandalonePostgresql::create([
            'uuid' => fake()->uuid(),
            'name' => 'database-without-project',
            'postgres_password' => 'password',
            'environment_id' => null,
            'destination_id' => $this->destinationA->id,
            'destination_type' => StandaloneDocker::class,
        ]));

        $component = Livewire::test(DestinationResources::class, ['destination_uuid' => $this->destinationA->uuid])
            ->assertSee('database-without-project');

        expect($component->html())->not->toContain('href=""');
    });
});
