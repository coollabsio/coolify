<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Livewire\store;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Verify docker_compose_raw is synced to the Livewire component after the
 * compose file is loaded from Git.
 *
 * Without the sync, the Monaco editor stays empty because the component
 * property is not updated after loadComposeFile() completes.
 */
it('syncs docker_compose_raw to component property after loading compose file', function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $storedApplication = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => null,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $composeContent = "services:\n  web:\n    image: nginx\n";

    // Stub only the Git read; everything else is the real model.
    $application = new class extends Application
    {
        protected $table = 'applications';

        public static string $loadedCompose = '';

        public function getForeignKey(): string
        {
            return 'application_id';
        }

        public function getMorphClass(): string
        {
            return Application::class;
        }

        public function loadComposeFile($isInit = false, ?string $restoreBaseDirectory = null, ?string $restoreDockerComposeLocation = null)
        {
            self::query()->whereKey($this->getKey())->update(['docker_compose_raw' => self::$loadedCompose]);

            return [
                'parsedServices' => collect(['services' => ['web' => ['image' => 'nginx']]]),
                'initialDockerComposeLocation' => '/docker-compose.yaml',
            ];
        }
    };
    $application::$loadedCompose = $composeContent;
    $application = $application->newFromBuilder($storedApplication->getAttributes());

    $component = new General;
    $component->application = $application;

    expect($component->dockerComposeRaw)->toBeNull();

    $component->loadComposeFile(showToast: false);

    $dispatchedEvents = collect(store($component)->get('dispatched'))
        ->map(fn ($event): string => $event->serialize()['name']);

    expect($dispatchedEvents)->toContain('compose_loaded')->not->toContain('error')
        ->and($component->dockerComposeRaw)->toBe($composeContent)
        ->and($component->parsedServices->all())->toBe(['services' => ['web' => ['image' => 'nginx']]]);
});
