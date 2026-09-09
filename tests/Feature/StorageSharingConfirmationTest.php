<?php

use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Shared\Storages\All;
use App\Livewire\Project\Shared\Storages\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    StandaloneDocker::withoutEvents(function () use ($server) {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Storage App',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
    ]);

    $this->volume = LocalPersistentVolume::create([
        'uuid' => (string) Str::uuid(),
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'host_path' => null,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_preview_suffix_enabled' => true,
    ]);

    $this->fileStorage = LocalFileVolume::withoutEvents(fn () => LocalFileVolume::forceCreate([
        'uuid' => (string) Str::uuid(),
        'fs_path' => '/data/config.yml',
        'mount_path' => '/app/config.yml',
        'content' => 'key: value',
        'is_directory' => false,
        'is_based_on_git' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
        'is_preview_suffix_enabled' => true,
    ]));
});

it('asks for confirmation before sharing a volume from the list and scopes the events', function () {
    $component = Livewire::test(All::class, ['resource' => $this->application]);
    $scope = $component->instance()->getId();

    $component->call('requestPreviewSuffixChange', $this->volume->id, false)
        ->assertSet('pendingSharedStorageId', $this->volume->id)
        ->assertSet("forms.{$this->volume->id}.isPreviewSuffixEnabled", true)
        ->assertDispatched('storage-sharing-pending', scope: $scope, storageId: $this->volume->id)
        ->assertDispatched('open-storage-sharing-modal', scope: $scope);

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeTrue();

    $component->call('confirmShareStorage')
        ->assertSet('pendingSharedStorageId', null)
        ->assertSet("forms.{$this->volume->id}.isPreviewSuffixEnabled", false)
        ->assertDispatched('storage-sharing-confirmed', scope: $scope, storageId: $this->volume->id);

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeFalse();
});

it('keeps the volume isolated when sharing is cancelled from the list', function () {
    $component = Livewire::test(All::class, ['resource' => $this->application]);
    $scope = $component->instance()->getId();

    $component->call('requestPreviewSuffixChange', $this->volume->id, false)
        ->call('cancelShareStorage')
        ->assertSet('pendingSharedStorageId', null)
        ->assertDispatched('storage-sharing-pending', scope: $scope, storageId: $this->volume->id);

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeTrue();
});

it('re-enables the suffix from the list without confirmation', function () {
    $this->volume->update(['is_preview_suffix_enabled' => false]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('requestPreviewSuffixChange', $this->volume->id, true)
        ->assertNotDispatched('open-storage-sharing-modal');

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeTrue();
});

it('asks for confirmation before sharing a single volume and scopes the events', function () {
    $component = Livewire::test(Show::class, ['storage' => $this->volume, 'resource' => $this->application]);
    $scope = $component->instance()->getId();

    $component->set('isPreviewSuffixEnabled', false)
        ->call('instantSave')
        ->assertSet('isPreviewSuffixEnabled', true)
        ->assertDispatched('storage-sharing-pending', scope: $scope)
        ->assertDispatched('open-storage-sharing-modal', scope: $scope);

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeTrue();

    $component->call('confirmShareStorage')
        ->assertSet('isPreviewSuffixEnabled', false)
        ->assertDispatched('storage-sharing-confirmed', scope: $scope);

    expect($this->volume->fresh()->is_preview_suffix_enabled)->toBeFalse();

    $component->call('cancelShareStorage')
        ->assertSet('isPreviewSuffixEnabled', true)
        ->assertDispatched('storage-sharing-pending', scope: $scope);
});

it('asks for confirmation before sharing a file storage path and scopes the events', function () {
    $component = Livewire::test(FileStorage::class, ['fileStorage' => $this->fileStorage]);
    $scope = $component->instance()->getId();

    $component->set('isPreviewSuffixEnabled', false)
        ->call('instantSave')
        ->assertSet('isPreviewSuffixEnabled', true)
        ->assertDispatched('storage-sharing-pending', scope: $scope)
        ->assertDispatched('open-storage-sharing-modal', scope: $scope);

    expect($this->fileStorage->fresh()->is_preview_suffix_enabled)->toBeTrue();

    $component->call('confirmShareStorage')
        ->assertSet('isPreviewSuffixEnabled', false)
        ->assertDispatched('storage-sharing-confirmed', scope: $scope);

    expect($this->fileStorage->fresh()->is_preview_suffix_enabled)->toBeFalse();

    $component->call('cancelShareStorage')
        ->assertSet('isPreviewSuffixEnabled', true)
        ->assertDispatched('storage-sharing-pending', scope: $scope);
});

it('renders one confirmation modal per component bound to that component scope', function () {
    $component = Livewire::test(FileStorage::class, ['fileStorage' => $this->fileStorage]);
    $scope = $component->instance()->getId();

    $component->assertSeeHtml('scope: \''.$scope.'\'')
        ->assertSeeHtml("\$event.detail.scope === '{$scope}' && (value = true)");

    $modal = file_get_contents(resource_path('views/components/storage-sharing-confirmation.blade.php'));
    expect($modal)->toContain('$event.detail.scope === scope && (modalOpen = true)');
});

it('forbids members from confirming or cancelling storage sharing', function () {
    $this->actingAs($this->member);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('cancelShareStorage')
        ->assertForbidden();

    Livewire::test(Show::class, ['storage' => $this->volume, 'resource' => $this->application])
        ->call('cancelShareStorage')
        ->assertForbidden();

    Livewire::test(FileStorage::class, ['fileStorage' => $this->fileStorage])
        ->call('confirmShareStorage')
        ->assertForbidden();

    expect($this->fileStorage->fresh()->is_preview_suffix_enabled)->toBeTrue();
});
