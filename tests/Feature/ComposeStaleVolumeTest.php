<?php

use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const STALE_VOLUME_THREE_VOLUMES_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'first:/first'
      - 'second:/second'
      - 'third:/third'
YAML;

const STALE_VOLUME_TWO_VOLUMES_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'first:/first'
      - 'second:/second'
YAML;

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);
    $this->withoutVite();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => STALE_VOLUME_THREE_VOLUMES_COMPOSE,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();
    applicationParser($this->application);

    $this->application->update(['docker_compose_raw' => STALE_VOLUME_TWO_VOLUMES_COMPOSE]);
    applicationParser($this->application->fresh());
});

function staleVolume(string $volume): LocalPersistentVolume
{
    return LocalPersistentVolume::where('name', test()->application->uuid.'_'.$volume)->sole();
}

it('offers to delete only the volume that the Compose file no longer declares', function () {
    $forms = Livewire::test(All::class, ['resource' => $this->application->fresh()])->get('forms');

    expect($forms[staleVolume('third')->id]['canDeleteStale'])->toBeTrue()
        ->and($forms[staleVolume('first')->id]['canDeleteStale'])->toBeFalse()
        ->and($forms[staleVolume('second')->id]['canDeleteStale'])->toBeFalse();
});

it('deletes the entry of a volume that the Compose file no longer declares', function (bool $deleteDockerVolume) {
    $name = staleVolume('third')->name;
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array(remoteProcessScript($process)) ? implode(' ', remoteProcessScript($process)) : remoteProcessScript($process);

        return Process::result(output: '');
    });

    Livewire::test(All::class, ['resource' => $this->application->fresh()])
        ->call('delete', staleVolume('third')->id, 'password', $deleteDockerVolume ? ['deleteDockerVolume'] : [])
        ->assertReturned(true);

    expect(LocalPersistentVolume::where('name', $name)->exists())->toBeFalse()
        ->and(implode("\n", $commands))->{$deleteDockerVolume ? 'toContain' : 'not'}("docker volume rm -f '{$name}'");

    applicationParser($this->application->fresh());
    expect(LocalPersistentVolume::where('name', $name)->exists())->toBeFalse();
})->with([
    'keep the Docker volume' => [false],
    'delete the Docker volume' => [true],
]);

it('does not delete the entry of a volume that the Compose file declares', function () {
    $volume = staleVolume('first');

    Livewire::test(All::class, ['resource' => $this->application->fresh()])
        ->call('delete', $volume->id, 'password')
        ->assertReturned(false);

    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
});

it('does not offer to delete the preview volume of a volume that the Compose file declares', function () {
    ApplicationPreview::create([
        'uuid' => 'stale-volume-preview',
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
    ]);
    applicationParser($this->application->fresh(), 42);
    $previewVolume = staleVolume('first-pr-42');

    Livewire::test(All::class, ['resource' => $this->application->fresh()])
        ->assertSet("forms.{$previewVolume->id}.canDeleteStale", false)
        ->call('delete', $previewVolume->id, 'password')
        ->assertReturned(false);

    expect(LocalPersistentVolume::find($previewVolume->id))->not->toBeNull();
});

it('offers to delete the left-over volume of a closed preview', function () {
    $preview = ApplicationPreview::create([
        'uuid' => 'stale-volume-closed-preview',
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/7',
    ]);
    applicationParser($this->application->fresh(), 7);
    $previewVolume = staleVolume('first-pr-7');
    $preview->deleteQuietly();

    Livewire::test(All::class, ['resource' => $this->application->fresh()])
        ->assertSet("forms.{$previewVolume->id}.canDeleteStale", true)
        ->call('delete', $previewVolume->id, 'password')
        ->assertReturned(true);

    expect(LocalPersistentVolume::find($previewVolume->id))->toBeNull();
});
