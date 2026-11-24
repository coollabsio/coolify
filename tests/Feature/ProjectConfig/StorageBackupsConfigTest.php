<?php

use App\Jobs\DeleteResourceJob;
use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class, ServerStorageSaveJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id])->fresh();
    $this->ctx = ApplyContext::for($this->link);
    app()->instance(HeldDeployments::class, new class extends HeldDeployments
    {
        public function __construct() {}

        public function deploy(Application $application, ?string $commit): void {}

        public function restart(Model $resource, bool $created): void {}
    });
    $this->s3 = storageBackupsS3($this->fx['team'], 'backups');
});

function storageBackupsS3(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name, 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret', 'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com', 'team_id' => $team->id, 'is_usable' => true,
    ]);
}

function storageBackupsFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
}

function storageBackupsPlan(array $resources): ConfigPlan
{
    return app(ConfigPlanner::class)->plan(app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse(storageBackupsFile($resources)), 'main'), test()->ctx);
}

function storageBackupsApply(array $resources): EnvironmentConfigRevision
{
    $revision = app(ConfigRevisions::class)->record(test()->link, storageBackupsFile($resources), 'api');
    app(ConfigApplier::class)->apply($revision);

    return $revision->fresh();
}

function storageBackupsApp(array $extra = []): array
{
    return ['api' => array_merge([
        'type' => 'application',
        'server' => 'prod-1',
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'],
        'ports' => ['exposes' => '3000'],
        'storages' => ['app-data' => ['mount' => '/data']],
    ], $extra)];
}

const STORAGE_BACKUPS_SCHEDULE = [
    'frequency' => '0 0,12 * * *',
    'stop_during_backup' => false,
    'save_s3' => true,
    's3_storage' => 'backups',
    'retention_s3_amount' => 28,
];

it('creates an application volume backup schedule from the file and plans nothing afterwards', function () {
    $desired = storageBackupsApp(['storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]);

    $revision = storageBackupsApply($desired);

    expect($revision->status->value)->toBe('applied', (string) $revision->error);
    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->frequency)->toBe('0 0,12 * * *')
        ->and($backup->save_s3)->toBeTrue()
        ->and($backup->retention_amount_s3)->toBe(28)
        ->and($backup->retention_amount_locally)->toBe(7)
        ->and($backup->timeout)->toBe(ScheduledVolumeBackup::DEFAULT_TIMEOUT)
        ->and($backup->s3_storage_id)->toBe($this->s3->id)
        ->and($backup->selectedS3Storages()->pluck('id')->all())->toBe([$this->s3->id])
        ->and($backup->team_id)->toBe($this->fx['team']->id)
        ->and($backup->backupable->mount_path)->toBe('/data');

    $plan = storageBackupsPlan($desired);
    expect($plan->errors)->toBe([])->and(array_map(fn ($c) => $c->path, $plan->changes))->toBe([]);

    $exported = app(ConfigExporter::class)->export($this->fx['environment']);
    expect(data_get(json_decode(json_encode($exported), true), 'resources.api.storage_backups.app-data'))
        ->toBe(['frequency' => '0 0,12 * * *', 'retention_s3_amount' => 28, 's3_storage' => 'backups', 'save_s3' => true]);
});

it('shows a schedule edited in Coolify as drift and puts the file value back', function () {
    $desired = storageBackupsApp(['storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]);
    storageBackupsApply($desired);
    ScheduledVolumeBackup::query()->sole()->update(['frequency' => 'daily']);

    expect(array_map(fn ($c) => $c->path, storageBackupsPlan($desired)->changes))->toBe(['storage_backups.app-data.frequency']);

    storageBackupsApply($desired);
    expect(ScheduledVolumeBackup::query()->sole()->frequency)->toBe('0 0,12 * * *');
});

it('removes a schedule and its volume in one apply and keeps the archives', function () {
    $storages = ['app-data' => ['mount' => '/data'], 'cache' => ['mount' => '/cache']];
    storageBackupsApply(storageBackupsApp(['storages' => $storages, 'storage_backups' => [
        'app-data' => STORAGE_BACKUPS_SCHEDULE,
        'cache' => ['frequency' => 'daily'],
    ]]));
    $cacheBackup = ScheduledVolumeBackup::query()->where('frequency', 'daily')->sole();
    $cacheBackup->executions()->create(['status' => 'success', 'filename' => '/data/coolify/backups/archive.tar.gz']);

    $plan = storageBackupsPlan(storageBackupsApp(['storages' => $storages, 'storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]));
    expect(array_map(fn ($c) => [$c->path, $c->destructive], $plan->changes))->toBe([['storage_backups.cache', true]]);

    $revision = storageBackupsApply(storageBackupsApp(['storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]));
    expect($revision->status->value)->toBe('waiting_approval');
    $revision->update(['approved_at' => now(), 'approved_by' => $this->fx['user']->id]);
    app(ConfigApplier::class)->apply($revision->fresh());
    $revision = $revision->fresh();

    expect($revision->status->value)->toBe('applied', (string) $revision->error)
        ->and(ScheduledVolumeBackup::query()->pluck('frequency')->all())->toBe(['0 0,12 * * *'])
        ->and(LocalPersistentVolume::query()->pluck('mount_path')->all())->toBe(['/data']);
});

it('validates storage backups at plan time', function () {
    $other = storageBackupsS3(Team::factory()->create(), 'foreign');

    $plan = storageBackupsPlan(storageBackupsApp(['storage_backups' => [
        'missing' => ['frequency' => 'daily'],
        'app-data' => ['frequency' => 'not a cron', 'save_s3' => true, 's3_storage' => $other->name, 'disable_local_backup' => true, 'timeout' => 5],
    ]]));

    expect(array_map(fn ($e) => $e->path, $plan->errors))->toEqualCanonicalizing([
        'resources.api.storage_backups.missing',
        'resources.api.storage_backups.app-data.frequency',
        'resources.api.storage_backups.app-data.s3_storage',
        'resources.api.storage_backups.app-data.timeout',
    ]);

    $plan = storageBackupsPlan(storageBackupsApp(['storage_backups' => ['app-data' => ['frequency' => 'daily', 'save_s3' => true, 'disable_local_backup' => false]]]));
    expect(array_map(fn ($e) => $e->path, $plan->errors))->toBe(['resources.api.storage_backups.app-data.s3_storage']);

    $plan = storageBackupsPlan(storageBackupsApp(['storage_backups' => ['app-data' => ['frequency' => 'daily', 'disable_local_backup' => true]]]));
    expect(array_map(fn ($e) => $e->path, $plan->errors))->toBe(['resources.api.storage_backups.app-data.disable_local_backup']);
});

it('backs up a named volume of a Compose service and keeps several S3 storages in file order', function () {
    $second = storageBackupsS3($this->fx['team'], 'offsite');
    $compose = "services:\n  web:\n    image: 'nginx:alpine'\n    volumes:\n      - 'app-data:/data'\nvolumes:\n  app-data: {}\n";
    $desired = ['stack' => [
        'type' => 'service', 'server' => 'prod-1', 'compose' => $compose,
        'storage_backups' => ['app-data' => [...STORAGE_BACKUPS_SCHEDULE, 's3_storage' => ['offsite', 'backups']]],
    ]];

    $revision = storageBackupsApply($desired);

    expect($revision->status->value)->toBe('applied', (string) $revision->error);
    $service = Service::where('config_key', 'stack')->sole();
    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->backupable->name)->toBe("{$service->uuid}_app-data")
        ->and($backup->s3_storage_id)->toBe($second->id)
        ->and($backup->selectedS3Storages()->pluck('name')->all())->toBe(['offsite', 'backups']);

    $plan = storageBackupsPlan($desired);
    expect($plan->errors)->toBe([])->and(array_map(fn ($c) => $c->path, $plan->changes))->toBe([]);

    $unknown = storageBackupsPlan(['stack' => [...$desired['stack'], 'storage_backups' => ['nope' => ['frequency' => 'daily']]]]);
    expect(array_map(fn ($e) => $e->path, $unknown->errors))->toBe(['resources.stack.storage_backups.nope']);
});

it('needs team-wide write access to use an S3 storage in a storage backup', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    $resolved = app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse(storageBackupsFile(storageBackupsApp(['storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]))), 'main');

    $paths = array_map(fn ($e) => $e->path, app(ConfigPlanner::class)->plan($resolved, ApplyContext::for($this->link, $member))->errors);

    expect($paths)->toContain('resources.api.storage_backups.app-data.s3_storage');
});

it('leaves schedules made in the dashboard alone when the file has no storage_backups', function () {
    storageBackupsApply(storageBackupsApp());
    $volume = LocalPersistentVolume::query()->sole();
    $volume->scheduledBackups()->create(['team_id' => $this->fx['team']->id, 'frequency' => 'daily']);

    expect(array_map(fn ($c) => $c->path, storageBackupsPlan(storageBackupsApp())->changes))->toBe([]);

    $revision = storageBackupsApply(storageBackupsApp(['ports' => ['exposes' => '4000']]));

    expect($revision->status->value)->toBe('applied', (string) $revision->error)
        ->and(ScheduledVolumeBackup::query()->sole()->frequency)->toBe('daily');
});

it('removes every schedule when storage_backups is an empty object', function () {
    storageBackupsApply(storageBackupsApp(['storage_backups' => ['app-data' => STORAGE_BACKUPS_SCHEDULE]]));

    expect(array_map(fn ($c) => $c->path, storageBackupsPlan(storageBackupsApp(['storage_backups' => new stdClass]))->changes))->toBe(['storage_backups']);

    $revision = storageBackupsApply(storageBackupsApp(['storage_backups' => new stdClass]));

    expect($revision->status->value)->toBe('applied', (string) $revision->error)
        ->and(ScheduledVolumeBackup::query()->exists())->toBeFalse()
        ->and(LocalPersistentVolume::query()->exists())->toBeTrue();
});
