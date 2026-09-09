<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

const PREVIEW_FILE_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - ./config.yml:/app/config.yml
      - ./data:/app/data
YAML;

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => PREVIEW_FILE_COMPOSE,
    ]);
    $this->baseDir = application_configuration_dir()."/{$this->application->uuid}";
});

function seedPreviewFileRow(Application $application, string $baseDir, string $fileName, string $mountPath, ?string $content, bool $isDirectory = false, bool $suffix = true): LocalFileVolume
{
    return LocalFileVolume::withoutEvents(fn () => LocalFileVolume::forceCreate([
        'uuid' => (string) new Cuid2,
        'fs_path' => "{$baseDir}/{$fileName}",
        'mount_path' => $mountPath,
        'content' => $content,
        'is_directory' => $isDirectory,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => $suffix,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]));
}

it('keeps the production path on the row while the preview compose uses the suffixed path', function () {
    $row = seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value');

    $parsed = applicationParser($this->application, pull_request_id: 42);

    expect($row->fresh()->fs_path)->toBe("{$this->baseDir}/config.yml")
        ->and($row->fresh()->content)->toBe('key: value')
        ->and(data_get($parsed, 'services.app.volumes.0'))->toBe("{$this->baseDir}/config.yml-pr-42:/app/config.yml")
        ->and($this->application->fileStorages()->count())->toBe(2);

    // parse() also runs from the UI and the preview delete hook, so it must not write preview copies.
    // (The production save for the newly created ./data row is dispatched by the model's created hook.)
    Bus::assertNotDispatched(ServerStorageSaveJob::class, fn (ServerStorageSaveJob $job): bool => $job->pullRequestId !== 0);
});

it('uses the production path in the preview compose when the storage is shared with production', function () {
    seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value', suffix: false);

    $parsed = applicationParser($this->application, pull_request_id: 42);

    expect(data_get($parsed, 'services.app.volumes.0'))->toBe("{$this->baseDir}/config.yml:/app/config.yml");
});

it('writes preview copies from the deployment before the stack starts', function () {
    seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value');
    seedPreviewFileRow($this->application, $this->baseDir, 'data', '/app/data', null, isDirectory: true, suffix: false);
    Process::fake(['*' => Process::result(output: 'NOK')]);

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    (function (Application $application): void {
        $this->application = $application;
        $this->pull_request_id = 42;
    })->call($job, $this->application);
    (fn () => $this->write_preview_file_storages())->call($job);

    Process::assertRan(fn ($process) => str_contains($process->command, "tee '{$this->baseDir}/config.yml-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "'{$this->baseDir}/config.yml'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "{$this->baseDir}/data"));

    expect(dockerComposeDeploySource())
        ->toContain("if (\$this->pull_request_id !== 0) {\n                \$this->write_preview_file_storages();");
});

function dockerComposeDeploySource(): string
{
    $source = file_get_contents(base_path('app/Jobs/ApplicationDeploymentJob.php'));
    $start = strpos($source, 'private function deploy_docker_compose_buildpack(): void');
    $end = strpos($source, 'private function write_preview_file_storages(): void', $start);

    return substr($source, $start, $end - $start);
}

it('keeps the row stable when production parses after a preview parse', function () {
    $row = seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value');

    applicationParser($this->application, pull_request_id: 42);
    applicationParser($this->application->refresh());
    $production = applicationParser($this->application->refresh());

    expect($row->fresh()->fs_path)->toBe("{$this->baseDir}/config.yml")
        ->and(data_get($production, 'services.app.volumes.0'))->toBe("{$this->baseDir}/config.yml:/app/config.yml");
});

it('resolves the preview path only when the suffix is enabled', function () {
    $suffixed = seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'x');
    $shared = seedPreviewFileRow($this->application, $this->baseDir, 'data', '/app/data', null, isDirectory: true, suffix: false);

    expect($suffixed->fsPathForPullRequest(42))->toBe("{$this->baseDir}/config.yml-pr-42")
        ->and($suffixed->fsPathForPullRequest(0))->toBe("{$this->baseDir}/config.yml")
        ->and($shared->fsPathForPullRequest(42))->toBe("{$this->baseDir}/data");
});

it('writes and removes the preview copy at the suffixed path', function () {
    $row = seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value');
    Process::fake(['*' => Process::result(output: 'NOK')]);

    $row->saveStorageOnServer(42);

    Process::assertRan(fn ($process) => str_contains($process->command, "tee '{$this->baseDir}/config.yml-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "tee '{$this->baseDir}/config.yml'"));

    Process::fake(['*' => Process::result(output: 'OK')]);

    $row->deleteStorageOnServer(42);

    Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf '{$this->baseDir}/config.yml-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "rm -rf '{$this->baseDir}/config.yml'"));
});

it('removes preview copies of bind mounts on preview delete and keeps production files', function () {
    seedPreviewFileRow($this->application, $this->baseDir, 'config.yml', '/app/config.yml', 'key: value');
    seedPreviewFileRow($this->application, $this->baseDir, 'data', '/app/data', null, isDirectory: true, suffix: false);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
    ]);
    Process::fake(['*' => Process::result(output: 'OK')]);

    $preview->forceDelete();

    Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf '{$this->baseDir}/config.yml-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "rm -rf '{$this->baseDir}/config.yml'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "rm -rf '{$this->baseDir}/data"));
    expect($this->application->fileStorages()->count())->toBe(2)
        ->and($this->application->fileStorages()->where('mount_path', '/app/config.yml')->value('fs_path'))->toBe("{$this->baseDir}/config.yml");
});
