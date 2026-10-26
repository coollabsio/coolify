<?php

use App\Livewire\Project\Application\Backup\Create;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'readlink -f') ? 'OK' : ''
    ));
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false]
    ));
});

/**
 * @return array{0: Application, 1: LocalPersistentVolume, 2: Team}
 */
function createPerfApplicationWithVolumes(int $volumeCount = 5): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
    $server->settings()->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
    ]);
    $application->setRelation('environment', $environment);
    $environment->setRelation('project', $project);

    $firstVolume = null;
    for ($i = 0; $i < $volumeCount; $i++) {
        $volume = LocalPersistentVolume::create([
            'name' => $application->uuid.'-vol-'.$i,
            'mount_path' => '/data/'.$i,
            'host_path' => null,
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
            'is_preview_suffix_enabled' => true,
        ]);
        $firstVolume ??= $volume;
    }

    LocalFileVolume::create([
        'fs_path' => application_configuration_dir().'/'.$application->uuid.'/config.env',
        'mount_path' => '/app/config.env',
        'content' => str_repeat('x', 5000),
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    $application = $application->fresh(['environment.project', 'destination.server', 'persistentStorages']);

    return [$application, $firstVolume, $team];
}

it('renders volume rows inline without nested Livewire row components', function () {
    [$application] = createPerfApplicationWithVolumes(5);

    $component = Livewire::test(All::class, ['resource' => $application]);

    expect($component->html())
        ->toContain('data-table')
        ->toContain('wire:submit="submit(')
        ->not->toContain('shared-configure-volume-backup-');

    // The only nested components are the per-row backup forms, keyed by volume id.
    expect(array_keys($component->snapshot['memo']['children']))
        ->toBe($application->persistentStorages->map(fn ($storage) => "configure-volume-backup-{$storage->id}")->values()->all());
});

it('batches volume backup meta and exposes forms for every volume', function () {
    [$application, , $team] = createPerfApplicationWithVolumes(5);

    foreach ($application->persistentStorages as $index => $storage) {
        $storage->scheduledBackups()->create([
            'team_id' => $team->id,
            'frequency' => 'daily',
            'enabled' => true,
            'save_s3' => $index === 0,
        ]);
    }

    $component = Livewire::test(All::class, ['resource' => $application]);

    expect($component->get('volumeBackupMeta'))->toHaveCount(5)
        ->and($component->get('forms'))->toHaveCount(5)
        ->and($component->html())->toContain('volumes-col-backup')
        ->toContain('Backups are saved to S3')
        ->toContain('Backups are stored locally only');

    expect($component->get("volumeBackupMeta.{$application->persistentStorages->first()->id}.s3"))->toBeTrue();

    foreach ($component->get('volumeBackupMeta') as $meta) {
        expect($meta['enabled'])->toBeTrue()
            ->and($meta['url'])->not->toBeNull();
    }
});

it('updates a volume row from the parent All component', function () {
    [$application, $volume] = createPerfApplicationWithVolumes(2);

    Livewire::test(All::class, ['resource' => $application])
        ->set("forms.{$volume->id}.mountPath", '/data/updated')
        ->call('submit', $volume->id)
        ->assertDispatched('success');

    expect($volume->fresh()->mount_path)->toBe('/data/updated');
});

it('mounts a backup form per volume row keyed by the volume id', function () {
    [$application, $volume] = createPerfApplicationWithVolumes(3);

    $component = Livewire::test(All::class, ['resource' => $application]);

    expect($component->snapshot['memo']['children'])
        ->toHaveCount(3)
        ->toHaveKey("configure-volume-backup-{$volume->id}");

    $component
        ->assertSee('Configure Volume Backup')
        ->assertSee('Frequency');
});

it('keeps file mount content out of the volumes tab snapshot', function () {
    [$application] = createPerfApplicationWithVolumes(2);

    $component = Livewire::test(Storage::class, ['resource' => $application]);

    expect($component->get('activeTab'))->toBe('volumes')
        ->and($component->get('fileCount'))->toBe(1)
        ->and($component->get('volumeCount'))->toBe(2)
        ->and(collect($component->get('fileStorage')))->toHaveCount(0);

    $component->call('setActiveTab', 'files')
        ->assertSet('activeTab', 'files');

    expect(collect($component->get('fileStorage')))->toHaveCount(1);
});

it('loads only the locked target when opening backup create from a volume row', function () {
    [$application, $volume] = createPerfApplicationWithVolumes(4);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component = Livewire::test(Create::class, [
        'application' => $application,
        'selectedTargetKey' => 'volume:'.$volume->id,
    ]);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($component->get('targetLocked'))->toBeTrue()
        ->and($component->get('targets'))->toHaveCount(1)
        ->and($queryCount)->toBeLessThan(15);
});
