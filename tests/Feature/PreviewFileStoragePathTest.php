<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

const PREVIEW_FILE_STORAGE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - ./data:/app/data
      - type: bind
        source: ./config/app.conf
        target: /etc/app.conf
        content: 'listen 8080;'
YAML;

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => PREVIEW_FILE_STORAGE_COMPOSE,
    ]);
    $this->workdir = $this->application->workdir();
});

/**
 * @return array<string, string> mount path => stored host path
 */
function previewFileStoragePaths(Application $application): array
{
    return LocalFileVolume::query()->where('resource_id', $application->id)->orderBy('mount_path')->pluck('fs_path', 'mount_path')->all();
}

it('keeps the paths of the application file storages when it parses a pull request preview', function () {
    $this->application->parse();

    $preview = $this->application->parse(pull_request_id: 7);

    expect(previewFileStoragePaths($this->application))->toBe([
        '/app/data' => "{$this->workdir}/data",
        '/etc/app.conf' => "{$this->workdir}/config/app.conf",
    ]);
    $volumes = collect(data_get($preview, 'services.app-pr-7.volumes'))
        ->map(fn ($volume) => is_array($volume) ? $volume['source'].':'.$volume['target'] : $volume)
        ->all();
    expect($volumes)->toContain("{$this->workdir}/data-pr-7:/app/data")
        ->toContain("{$this->workdir}/config/app.conf-pr-7:/etc/app.conf");
});

it('stores the application path when the first parse is a pull request preview', function () {
    $this->application->parse(pull_request_id: 7);

    expect(previewFileStoragePaths($this->application))->toBe([
        '/app/data' => "{$this->workdir}/data",
        '/etc/app.conf' => "{$this->workdir}/config/app.conf",
    ]);
});

it('writes the file of a pull request preview to its own path without saving that path', function () {
    $this->application->parse();
    $storage = LocalFileVolume::query()->where('mount_path', '/etc/app.conf')->firstOrFail();

    $preview = $storage->forPullRequest(7);
    $preview->is_directory = false;
    $preview->save();

    expect($preview->contentPathOnServer())->toBe("{$this->workdir}/config/app.conf-pr-7")
        ->and($storage->contentPathOnServer())->toBe("{$this->workdir}/config/app.conf")
        ->and($storage->forPullRequest(0)->contentPathOnServer())->toBe("{$this->workdir}/config/app.conf")
        ->and($storage->fresh()->fs_path)->toBe("{$this->workdir}/config/app.conf");

    $storage->update(['is_preview_suffix_enabled' => false]);
    expect($storage->fresh()->forPullRequest(7)->contentPathOnServer())->toBe("{$this->workdir}/config/app.conf");
});
