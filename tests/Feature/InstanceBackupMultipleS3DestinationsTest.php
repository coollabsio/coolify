<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\SettingsBackup;
use App\Livewire\Storage\Resources as StorageResources;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function instanceBackupS3Storage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => str($name)->slug()->toString(),
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

beforeEach(function () {
    Server::flushIdentityMap();
    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => 0,
        'ip' => '127.0.0.1',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => 0])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail()->forceFill(['id' => 0])->save();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->database = new StandalonePostgresql;
    $this->database->forceFill([
        'id' => 0,
        'name' => 'coolify-db',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 0,
    ])->save();
    $this->backup = ScheduledDatabaseBackup::create([
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 0 * * *',
        'database_id' => 0,
        'database_type' => StandalonePostgresql::class,
        'team_id' => 0,
    ]);

    $this->firstStorage = instanceBackupS3Storage($this->rootTeam, 'First S3');
    $this->secondStorage = instanceBackupS3Storage($this->rootTeam, 'Second S3');
    $this->otherTeam = Team::factory()->create();
    $this->foreignStorage = instanceBackupS3Storage($this->otherTeam, 'Foreign S3');

    $user = User::factory()->create();
    $this->rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $user->teams()->attach($this->otherTeam->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);
});

it('offers every root team S3 storage on the instance backup page', function () {
    Livewire::test(SettingsBackup::class)
        ->assertOk()
        ->assertSee('First S3')
        ->assertSee('Second S3')
        ->assertDontSee('Foreign S3');
});

it('saves several S3 destinations for the instance backup and drops storages of other teams', function () {
    // The session team can change in another tab after the page loads.
    session(['currentTeam' => ['id' => $this->otherTeam->id]]);

    Livewire::test(BackupEdit::class, ['backup' => $this->backup->fresh(), 'availableS3Storages' => collect(), 'section' => 's3'])
        ->set('saveS3', true)
        ->set('s3StorageIds', [$this->firstStorage->id, $this->secondStorage->id, $this->foreignStorage->id])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $backup = $this->backup->fresh();

    expect($backup->save_s3)->toBeTruthy()
        ->and($backup->s3_storage_id)->toBe($this->firstStorage->id)
        ->and($backup->s3Storages()->pluck('s3_storages.id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id]);
});

it('uploads the instance backup to every selected destination', function () {
    $this->backup->forceFill(['save_s3' => true, 'databases_to_backup' => 'coolify'])->save();
    $this->backup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);
    Process::fake(fn ($process) => Process::result(output: str_contains(implode(' ', (array) $process->command), 'du -b') ? '128' : ''));

    $uploadedTo = collect();
    $job = Mockery::mock(DatabaseBackupJob::class.'[upload_to_s3]', [$this->backup->fresh()])->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('upload_to_s3')->andReturnUsing(fn (S3Storage $storage) => $uploadedTo->push($storage->id));
    $job->handle();

    $execution = ScheduledDatabaseBackupExecution::query()->sole();

    expect($uploadedTo->sort()->values()->all())->toBe([$this->firstStorage->id, $this->secondStorage->id])
        ->and($execution->status)->toBe('success')
        ->and($execution->s3_uploaded)->toBeTrue()
        ->and($execution->s3Replicas()->where('s3_uploaded', true)->pluck('s3_storage_id')->sort()->values()->all())
        ->toBe([$this->firstStorage->id, $this->secondStorage->id]);
});

it('links the instance backup from the storage resources page', function () {
    $this->backup->forceFill(['save_s3' => true])->save();
    $this->backup->syncS3Storages([$this->firstStorage->id, $this->secondStorage->id]);

    Livewire::test(StorageResources::class, ['storage' => $this->secondStorage])
        ->assertSee('coolify-db')
        ->assertSeeHtml(route('settings.backup'));
});
