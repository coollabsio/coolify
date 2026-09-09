<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const NAMED_VOLUME_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - data:/app/data
volumes:
  data:
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
        'docker_compose_raw' => NAMED_VOLUME_COMPOSE,
    ]);
    $this->previewVolumeName = "{$this->application->uuid}_data-pr-42";
});

function makePreview(Application $application, int $pullRequestId = 42): ApplicationPreview
{
    return ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/example/repository/pull/{$pullRequestId}",
    ]);
}

it('keeps production named volume rows on the application', function () {
    applicationParser($this->application);

    expect($this->application->persistentStorages()->pluck('name')->all())
        ->toBe(["{$this->application->uuid}_data"]);
});

it('writes named volume rows of a new preview to the preview', function () {
    $preview = makePreview($this->application);

    applicationParser($this->application, pull_request_id: 42);
    applicationParser($this->application->refresh(), pull_request_id: 42);

    expect($preview->persistentStorages()->pluck('name')->all())->toBe([$this->previewVolumeName])
        ->and($this->application->persistentStorages()->count())->toBe(0);
});

it('resolves the preview owner by preview id when provided', function () {
    $preview = makePreview($this->application);

    applicationParser($this->application, pull_request_id: 42, preview_id: $preview->id);

    expect($preview->persistentStorages()->pluck('name')->all())->toBe([$this->previewVolumeName]);
});

it('keeps legacy preview rows on the application for existing previews', function () {
    $preview = makePreview($this->application);
    $legacyRow = $this->application->persistentStorages()->create([
        'name' => $this->previewVolumeName,
        'mount_path' => '/old/path',
    ]);

    applicationParser($this->application, pull_request_id: 42);

    expect($legacyRow->fresh()->mount_path)->toBe('/app/data')
        ->and($this->application->persistentStorages()->count())->toBe(1)
        ->and($preview->persistentStorages()->count())->toBe(0);
});

it('falls back to application ownership and logs when the preview record does not exist', function () {
    Log::spy();

    $parsed = applicationParser($this->application, pull_request_id: 42);

    expect($this->application->persistentStorages()->pluck('name')->all())->toBe([$this->previewVolumeName])
        ->and(data_get($parsed, 'volumes'))->toHaveKey($this->previewVolumeName);

    Log::shouldHaveReceived('warning')->once()->with(
        Mockery::pattern('/ApplicationPreview/'),
        Mockery::on(fn (array $context): bool => $context['application_id'] === $this->application->id
            && $context['pull_request_id'] === 42
            && $context['volume'] === $this->previewVolumeName),
    );
});

it('removes preview owned rows when the preview is force deleted', function () {
    $preview = makePreview($this->application);
    applicationParser($this->application, pull_request_id: 42);
    Process::fake(['*' => Process::result(output: '')]);

    $preview->delete();
    $preview->forceDelete();

    expect(LocalPersistentVolume::query()->where('name', $this->previewVolumeName)->exists())->toBeFalse()
        ->and($this->application->persistentStorages()->count())->toBe(0);
    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume rm -f '{$this->previewVolumeName}'"));
});
