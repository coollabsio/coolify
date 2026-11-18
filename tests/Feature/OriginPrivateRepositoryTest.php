<?php

use App\Livewire\Project\Application\Source;
use App\Livewire\Project\New\OriginPrivateRepository;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\OriginApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Http::GitSource() pins DNS before Http::fake() answers, so use a public IP
 * literal that needs no DNS lookup. No request leaves the test.
 */
const ORIGIN_PICKER_TEST_API_URL = 'https://1.1.1.1/v1/origin';

function fakeOriginPickerApi(): void
{
    Http::fake([
        '1.1.1.1/v1/origin/app/installations/*/access_tokens' => Http::response(['token' => 'oit_test', 'expiresAt' => now()->addMinutes(5)->toIso8601String()], 201),
        '1.1.1.1/v1/origin/installation/repos*' => Http::response([
            'repositories' => [
                ['id' => 'repo_b', 'name' => 'zeta', 'fullName' => 'acme/zeta', 'owner' => ['slug' => 'acme'], 'defaultBranch' => 'main'],
                ['id' => 'repo_a', 'name' => 'rocket', 'fullName' => 'acme/rocket', 'owner' => ['slug' => 'acme'], 'defaultBranch' => 'production'],
            ],
            'nextPageToken' => '',
        ]),
        '1.1.1.1/v1/origin/repos/acme/rocket/branches*' => Http::response([
            'branches' => [
                ['name' => 'main', 'commit' => ['sha' => 'aaa']],
                ['name' => 'production', 'commit' => ['sha' => 'bbb']],
            ],
            'nextPageToken' => '',
        ]),
    ]);
}

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'coolify-'.fake()->unique()->word(),
    ]);

    $this->originApp = OriginApp::factory()->connected()->create([
        'team_id' => $this->team->id,
        'api_url' => ORIGIN_PICKER_TEST_API_URL,
    ]);
    $this->foreignOriginApp = OriginApp::factory()->connected()->create([
        'team_id' => Team::factory()->create()->id,
        'api_url' => ORIGIN_PICKER_TEST_API_URL,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('new application from an Origin repository', function () {
    test('lists only connected Origin Apps of the current team', function () {
        OriginApp::factory()->create(['team_id' => $this->team->id]);

        $component = Livewire::test(OriginPrivateRepository::class, ['type' => 'private-origin-app']);

        expect($component->get('origin_apps')->pluck('id')->all())->toBe([$this->originApp->id]);
    });

    test('loads repositories and branches and selects the default branch', function () {
        fakeOriginPickerApi();

        Livewire::test(OriginPrivateRepository::class, ['type' => 'private-origin-app'])
            ->call('loadRepositories', $this->originApp->id)
            ->assertSet('current_step', 'repository')
            ->assertSet('total_repositories_count', 2)
            ->assertSet('selected_repository_full_name', 'acme/rocket')
            ->call('loadBranches')
            ->assertSet('selected_branch_name', 'production')
            ->assertSet('branches', collect([['name' => 'main'], ['name' => 'production']]));
    });

    test('submit creates an application linked to the Origin App', function () {
        fakeOriginPickerApi();
        $routeParams = [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ];

        Livewire::test(OriginPrivateRepository::class, ['type' => 'private-origin-app'])
            ->set('parameters', $routeParams)
            ->set('query', ['destination' => $this->destination->uuid])
            ->call('loadRepositories', $this->originApp->id)
            ->call('loadBranches')
            ->call('submit')
            ->assertRedirect();

        $application = Application::query()->latest('id')->firstOrFail();

        expect($application->source_type)->toBe(OriginApp::class)
            ->and($application->source_id)->toBe($this->originApp->id)
            ->and($application->git_repository)->toBe('acme/rocket')
            ->and($application->git_branch)->toBe('production')
            ->and($application->repository_project_id)->toBeNull()
            ->and($application->environment_id)->toBe($this->environment->id);
    });

    test('cannot load repositories of another team Origin App', function () {
        fakeOriginPickerApi();

        Livewire::test(OriginPrivateRepository::class, ['type' => 'private-origin-app'])
            ->call('loadRepositories', $this->foreignOriginApp->id);
    })->throws(ModelNotFoundException::class);

    test('cannot submit with another team Origin App', function () {
        $before = Application::count();

        try {
            Livewire::test(OriginPrivateRepository::class, ['type' => 'private-origin-app'])
                ->set('parameters', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])
                ->set('query', ['destination' => $this->destination->uuid])
                ->set('origin_app_id', $this->foreignOriginApp->id)
                ->set('selected_repository_full_name', 'acme/rocket')
                ->set('selected_branch_name', 'main')
                ->call('submit');
        } catch (Throwable) {
        }

        expect(Application::count())->toBe($before);
    });
});

describe('changing an application source to Origin', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'git_repository' => 'acme/rocket',
            'repository_project_id' => 123,
            'source_id' => null,
            'source_type' => null,
        ]);
    });

    test('lists connected Origin Apps of the application team', function () {
        $component = Livewire::test(Source::class, ['application' => $this->application]);

        expect($component->get('sources')->filter(fn ($source) => $source instanceof OriginApp)->pluck('id')->all())
            ->toBe([$this->originApp->id]);
    });

    test('accepts an Origin App of the application team without calling any API', function () {
        Http::fake();

        Livewire::test(Source::class, ['application' => $this->application])
            ->call('changeSource', $this->originApp->id, OriginApp::class)
            ->assertDispatched('configurationChanged');

        $this->application->refresh();
        expect($this->application->source_type)->toBe(OriginApp::class)
            ->and($this->application->source_id)->toBe($this->originApp->id)
            ->and($this->application->repository_project_id)->toBeNull();
        Http::assertNothingSent();
    });

    test('rejects an Origin App of another team', function () {
        try {
            Livewire::test(Source::class, ['application' => $this->application])
                ->call('changeSource', $this->foreignOriginApp->id, OriginApp::class);
        } catch (Throwable) {
        }

        $this->application->refresh();
        expect($this->application->source_id)->toBeNull()
            ->and($this->application->source_type)->toBeNull();
    });
});
