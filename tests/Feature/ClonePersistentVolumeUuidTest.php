<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'redirect' => 'both',
    ]);

    $this->application->settings->fill([
        'is_container_label_readonly_enabled' => false,
    ])->save();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('cloning application generates new uuid for persistent volumes', function () {
    $volume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $originalUuid = $volume->uuid;

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedVolume = $newApp->persistentStorages()->first();

    expect($clonedVolume)->not->toBeNull();
    expect($clonedVolume->uuid)->not->toBe($originalUuid);
    expect($clonedVolume->mount_path)->toBe('/data');
});

test('cloning application with multiple persistent volumes generates unique uuids', function () {
    $volume1 = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $volume2 = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-config',
        'mount_path' => '/config',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedVolumes = $newApp->persistentStorages()->get();

    expect($clonedVolumes)->toHaveCount(2);

    $clonedUuids = $clonedVolumes->pluck('uuid')->toArray();
    $originalUuids = [$volume1->uuid, $volume2->uuid];

    // All cloned UUIDs should be unique and different from originals
    expect($clonedUuids)->each->not->toBeIn($originalUuids);
    expect(array_unique($clonedUuids))->toHaveCount(2);
});

test('cloning application reassigns settings to the cloned application', function () {
    $this->application->settings->fill([
        'is_static' => true,
        'is_spa' => true,
        'is_build_server_enabled' => true,
    ])->save();

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $sourceSettingsCount = ApplicationSetting::query()
        ->where('application_id', $this->application->id)
        ->count();
    $clonedSettings = ApplicationSetting::query()
        ->where('application_id', $newApp->id)
        ->first();

    expect($sourceSettingsCount)->toBe(1)
        ->and($clonedSettings)->not->toBeNull()
        ->and($clonedSettings?->application_id)->toBe($newApp->id)
        ->and($clonedSettings?->is_static)->toBeTrue()
        ->and($clonedSettings?->is_spa)->toBeTrue()
        ->and($clonedSettings?->is_build_server_enabled)->toBeTrue();
});

test('cloning application reassigns scheduled tasks and previews to the cloned application', function () {
    $scheduledTask = ScheduledTask::create([
        'uuid' => 'scheduled-task-original',
        'application_id' => $this->application->id,
        'team_id' => $this->team->id,
        'name' => 'nightly-task',
        'command' => 'php artisan schedule:run',
        'frequency' => '* * * * *',
        'container' => 'app',
        'timeout' => 120,
    ]);

    $preview = ApplicationPreview::create([
        'uuid' => 'preview-original',
        'application_id' => $this->application->id,
        'pull_request_id' => 123,
        'pull_request_html_url' => 'https://example.com/pull/123',
        'fqdn' => 'https://preview.example.com',
        'status' => 'running',
    ]);

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedTask = ScheduledTask::query()
        ->where('application_id', $newApp->id)
        ->first();
    $clonedPreview = ApplicationPreview::query()
        ->where('application_id', $newApp->id)
        ->first();

    expect($clonedTask)->not->toBeNull()
        ->and($clonedTask?->uuid)->not->toBe($scheduledTask->uuid)
        ->and($clonedTask?->application_id)->toBe($newApp->id)
        ->and($clonedTask?->team_id)->toBe($this->team->id)
        ->and($clonedPreview)->not->toBeNull()
        ->and($clonedPreview?->uuid)->not->toBe($preview->uuid)
        ->and($clonedPreview?->application_id)->toBe($newApp->id)
        ->and($clonedPreview?->status)->toBe('exited');
});
