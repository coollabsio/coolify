<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\BackupEdit;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createBackupForEditValidationTest(Team $team, array $overrides = []): ScheduledDatabaseBackup
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create([
        'name' => 'pg-backup-edit-validation',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return ScheduledDatabaseBackup::create(array_merge([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => null,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
    ], $overrides));
}

function createS3StorageForBackupEditValidationTest(Team|int $team, string $name = 'Backup Edit S3'): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team instanceof Team ? $team->id : $team,
    ]);
}

beforeEach(function () {
    if (InstanceSettings::find(0) === null) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders a highlighted enable backup button and a regular disable backup button', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/backup-edit/general.blade.php'));
    $s3View = file_get_contents(resource_path('views/livewire/project/database/backup-edit/s3.blade.php'));

    expect($view)
        ->toContain('wire:target="toggleEnabled" isHighlighted>Enable Backup</x-forms.button>')
        ->toContain('wire:target="toggleEnabled">Disable Backup</x-forms.button>')
        ->not->toContain('label="Backup Enabled"')
        ->and($s3View)
        ->toContain('wire:target="toggleS3" isHighlighted')
        ->toContain('wire:target="toggleS3">Disable S3</x-forms.button>')
        ->not->toContain('label="S3 Enabled"');
});

it('enables and disables S3 backups from the S3 title action', function () {
    $s3 = createS3StorageForBackupEditValidationTest($this->team);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => $s3->id,
    ]);

    $component = Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => $this->team->s3s,
        'section' => 's3',
    ])
        ->assertSee('Enable S3')
        ->call('toggleS3')
        ->assertSet('saveS3', true)
        ->assertSee('Disable S3');

    expect($backup->refresh()->save_s3)->toBeTruthy();

    $component->call('toggleS3')->assertSet('saveS3', false);

    expect($backup->refresh()->save_s3)->toBeFalsy();
});

it('shows and saves S3 retention while S3 backups are disabled', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'enabled' => false,
        'save_s3' => false,
        'database_backup_retention_amount_locally' => 0,
        'database_backup_retention_days_locally' => 0,
        'database_backup_retention_max_storage_locally' => 0,
        'database_backup_retention_amount_s3' => 0,
        'database_backup_retention_days_s3' => 0,
        'database_backup_retention_max_storage_s3' => 0,
        'dump_all' => false,
        'timeout' => 3600,
    ]);

    Livewire::test(BackupEdit::class, [
        'backup' => $backup,
        'availableS3Storages' => $this->team->s3s,
        'section' => 'retention',
    ])
        ->assertSee('S3 Storage Retention')
        ->set('databaseBackupRetentionAmountS3', 12)
        ->set('databaseBackupRetentionDaysS3', 30)
        ->set('databaseBackupRetentionMaxStorageS3', 4.5)
        ->call('submit')
        ->assertHasNoErrors();

    expect($backup->refresh()->save_s3)->toBeFalsy()
        ->and($backup->database_backup_retention_amount_s3)->toBe(12)
        ->and($backup->database_backup_retention_days_s3)->toBe(30)
        ->and($backup->database_backup_retention_max_storage_s3)->toBe(4.5);
});

it('splits standalone database backup settings and executions across dedicated urls', function () {
    config(['cache.default' => 'array', 'app.maintenance.driver' => 'file']);
    $backup = createBackupForEditValidationTest($this->team);
    $database = $backup->database;
    $parameters = [
        'project_uuid' => $database->project()->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ];
    $generalUrl = route('project.database.backup.execution', $parameters);

    $this->get($generalUrl)
        ->assertOk()
        ->assertSee('General')
        ->assertSee('S3')
        ->assertSee('Retention')
        ->assertSee('Executions')
        ->assertSee('Danger Zone')
        ->assertSee('Frequency')
        ->assertDontSee('S3 Enabled')
        ->assertDontSee('Number of backups to keep')
        ->assertDontSee('Cleanup Failed Backups')
        ->assertDontSee('Delete Backups and Schedule');

    $this->get($generalUrl.'/s3')
        ->assertOk()
        ->assertSeeText('No validated S3 storage')
        ->assertDontSee('Disable Local Backup')
        ->assertDontSee('Enable S3')
        ->assertDontSee('Disable S3')
        ->assertDontSee('S3 Storage Retention')
        ->assertDontSee('Local Backup Retention')
        ->assertDontSee('Frequency')
        ->assertDontSee('Cleanup Failed Backups');

    $s3View = file_get_contents(resource_path('views/livewire/project/database/backup-edit/s3.blade.php'));
    expect(strpos($s3View, '<span>S3 Storage</span>'))
        ->toBeLessThan(strpos($s3View, 'label="Disable Local Backup"'));

    $this->get($generalUrl.'/retention')
        ->assertOk()
        ->assertSee('Local Backup Retention')
        ->assertSee('S3 Storage Retention')
        ->assertSee('Number of backups to keep')
        ->assertDontSee('Frequency')
        ->assertDontSee('Cleanup Failed Backups');

    $this->get($generalUrl.'/executions')
        ->assertOk()
        ->assertSee('<h2 class="py-0">Executions</h2>', false)
        ->assertDontSee('Executions <span', false)
        ->assertSee('Cleanup Failed Backups')
        ->assertDontSee('Frequency')
        ->assertDontSee('Number of backups to keep');

    $this->get($generalUrl.'/danger')
        ->assertOk()
        ->assertSee('Danger Zone')
        ->assertSee('Delete Scheduled Backup')
        ->assertSee('Delete Backups and Schedule')
        ->assertDontSee('Frequency')
        ->assertDontSee('Number of backups to keep')
        ->assertDontSee('Cleanup Failed Backups');
});

it('enables and disables a scheduled database backup from the title action', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'enabled' => false,
        'save_s3' => false,
    ]);

    $component = Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSet('backupEnabled', false)
        ->assertSee('Enable Backup')
        ->call('toggleEnabled')
        ->assertSet('backupEnabled', true)
        ->assertSee('Disable Backup');

    expect($backup->refresh()->enabled)->toBeTruthy();

    $component->call('toggleEnabled')->assertSet('backupEnabled', false);

    expect($backup->refresh()->enabled)->toBeFalsy();
});

it('redirects to executions after queuing a database backup with unusable S3 storage', function () {
    Queue::fake();
    $s3Storage = createS3StorageForBackupEditValidationTest($this->team, 'Unavailable storage');
    $s3Storage->update(['is_usable' => false]);
    $backup = createBackupForEditValidationTest($this->team, [
        'enabled' => true,
        's3_storage_id' => $s3Storage->id,
        'database_backup_retention_amount_locally' => 7,
        'database_backup_retention_days_locally' => 0,
        'database_backup_retention_max_storage_locally' => 0,
        'database_backup_retention_amount_s3' => 7,
        'database_backup_retention_days_s3' => 0,
        'database_backup_retention_max_storage_s3' => 0,
        'dump_all' => false,
        'timeout' => 3600,
    ]);
    $database = $backup->database;
    $parameters = [
        'project_uuid' => $database->project()->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ];

    Livewire::test(BackupEdit::class, [
        'backup' => $backup,
        'availableS3Storages' => $this->team->s3s,
    ])
        ->call('backupNow')
        ->assertRedirectToRoute('project.database.backup.executions', $parameters);

    Queue::assertPushed(DatabaseBackupJob::class);
});

it('queues instance database backup without redirecting when project context is missing', function () {
    Queue::fake();

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $this->user->teams()->syncWithoutDetaching([$rootTeam->id => ['role' => 'owner']]);
    session(['currentTeam' => $rootTeam]);

    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $rootTeam->id,
        'ip' => '127.0.0.1',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::create([
            'id' => 0,
            'name' => 'coolify',
            'uuid' => (string) str()->uuid(),
            'network' => 'coolify',
            'server_id' => $server->id,
        ]);

    $database = new StandalonePostgresql;
    $database->forceFill([
        'id' => 0,
        'name' => 'coolify-db',
        'description' => 'Coolify database',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $destination->id,
        'environment_id' => null,
    ]);
    $database->save();

    expect($database->project())->toBeNull()
        ->and($database->environment)->toBeNull();

    $backup = ScheduledDatabaseBackup::create([
        'id' => 0,
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 0 * * *',
        'database_type' => StandalonePostgresql::class,
        'database_id' => $database->id,
        'team_id' => $rootTeam->id,
        'timeout' => 3600,
    ]);

    Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => collect(),
    ])
        ->assertSee('Retention')
        ->assertSee('S3 storage')
        ->assertSee('Local backups')
        ->assertSee('S3 backups')
        ->call('backupNow')
        ->assertDispatched('success', 'Backup queued. It will be available in a few minutes.')
        ->assertNoRedirect()
        ->assertHasNoErrors();

    Queue::assertPushed(DatabaseBackupJob::class);
});

it('disables S3 backup when saved without a selected S3 storage', function () {
    $backup = createBackupForEditValidationTest($this->team);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->call('submit')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
});

it('cascades to disabling local backup deletion when S3 is force-disabled', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'disable_local_backup' => true,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->call('submit')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
    expect($backup->disable_local_backup)->toBeFalsy();
});

it('keeps S3 enabled by selecting the only available team storage when none is selected yet', function () {
    createS3StorageForBackupEditValidationTest(Team::factory()->create());
    $s3 = createS3StorageForBackupEditValidationTest($this->team);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->set('saveS3', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('defaults to the first available storage when multiple storages are available', function () {
    $firstS3 = createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->assertSet('s3StorageId', $firstS3->id)
        ->set('saveS3', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($firstS3->id);
});

it('accepts the S3 storage scope passed to the component', function () {
    $s3 = createS3StorageForBackupEditValidationTest(0);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => collect([$s3])])
        ->set('saveS3', true)
        ->set('s3StorageId', $s3->id)
        ->call('instantSave')
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeTruthy();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('shows available S3 storages even when S3 backup is disabled', function () {
    createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s, 'section' => 's3'])
        ->assertSee('First S3')
        ->assertSee('Second S3');
});

it('shows only an empty S3 state when no storages are available', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s, 'section' => 's3'])
        ->assertSeeHtml('<h2>S3 storage</h2>')
        ->assertSeeText('No validated S3 storage')
        ->assertSeeHtml('href="'.route('storage.index').'"')
        ->assertSeeText('Open S3 storage')
        ->assertDontSee('Save')
        ->assertDontSee('Enable S3')
        ->assertDontSee('Disable S3')
        ->assertDontSee('S3 Storage')
        ->assertDontSee('Disable Local Backup')
        ->assertDontSee('No S3 storage available');
});

it('allows S3 backups to be disabled when no usable storage remains', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => true,
        's3_storage_id' => null,
    ]);

    $component = Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => collect(),
        'section' => 's3',
    ])
        ->assertSet('saveS3', true)
        ->assertSeeText('No validated S3 storage')
        ->assertDontSee('Disable S3')
        ->call('toggleS3')
        ->assertDispatched('success')
        ->assertSet('saveS3', false)
        ->assertDontSee('Enable S3');

    expect($backup->refresh()->save_s3)->toBeFalsy()
        ->and($backup->s3_storage_id)->toBeNull();

});

it('shows when S3 backups are currently disabled', function () {
    createS3StorageForBackupEditValidationTest($this->team);
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s, 'section' => 's3'])
        ->assertSee('S3 Storage')
        ->assertSee('(currently disabled)');
});

it('saves selected S3 storage immediately when it changes', function () {
    createS3StorageForBackupEditValidationTest($this->team, 'First S3');
    $secondS3 = createS3StorageForBackupEditValidationTest($this->team, 'Second S3');
    $backup = createBackupForEditValidationTest($this->team, [
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->team->s3s])
        ->set('s3StorageId', $secondS3->id)
        ->assertDispatched('success');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBe($secondS3->id);
});

it('subscribes to database status broadcasts so Backup Now can refresh without a full page reload', function () {
    $component = app(BackupEdit::class);
    $method = new ReflectionMethod($component, 'getListeners');
    $method->setAccessible(true);
    $listeners = (array) $method->invoke($component);

    expect($listeners)
        ->toHaveKey("echo-private:user.{$this->user->id},DatabaseStatusChanged")
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked")
        ->toHaveKey('databaseUpdated');
});

it('shows Backup Now after refresh when the database becomes running', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'enabled' => true,
    ]);
    $database = $backup->database;
    $database->update(['status' => 'exited:unhealthy']);

    $component = Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => $this->team->s3s,
        'status' => 'exited:unhealthy',
    ])
        ->assertDontSee('Backup Now')
        ->assertSet('status', 'exited:unhealthy');

    $database->update(['status' => 'running:healthy']);

    $component->call('refreshStatus')
        ->assertSet('status', 'running:healthy')
        ->assertSee('Backup Now');
});

it('hides Backup Now after refresh when the database stops', function () {
    $backup = createBackupForEditValidationTest($this->team, [
        'enabled' => true,
    ]);
    $database = $backup->database;
    $database->update(['status' => 'running:healthy']);

    $component = Livewire::test(BackupEdit::class, [
        'backup' => $backup->fresh(),
        'availableS3Storages' => $this->team->s3s,
        'status' => 'running:healthy',
    ])
        ->assertSee('Backup Now')
        ->assertSet('status', 'running:healthy');

    $database->update(['status' => 'exited:unhealthy']);

    $component->call('refreshStatus')
        ->assertSet('status', 'exited:unhealthy')
        ->assertDontSee('Backup Now');
});
