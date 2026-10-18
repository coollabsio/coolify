<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Service\BackupExecutions;
use App\Livewire\Project\Service\VolumeBackup\Index as ServiceVolumeBackupIndex;
use App\Livewire\Project\Shared\ScheduledTask\Add as AddScheduledTask;
use App\Livewire\Project\Shared\Tags;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->teamA, ['role' => 'owner']);
    $this->user->teams()->attach($this->teamB, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->teamA->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->teamA->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->service = Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
    $this->serviceDatabase = ServiceDatabase::create([
        'service_id' => $this->service->id,
        'name' => 'team-switch-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->teamA]);
});

function createS3ForTeamSwitchTest(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => $name.'-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

test('tags of the session team are not listed or attached after a team switch', function () {
    $foreignTag = Tag::create(['name' => 'production', 'team_id' => $this->teamB->id]);
    $component = Livewire::test(Tags::class, ['resource' => $this->application]);
    session(['currentTeam' => $this->teamB]);

    $component->call('loadTags')
        ->assertSet('tags', fn ($tags) => $tags->isEmpty())
        ->set('newTags', 'production')
        ->call('submit');
    Livewire::test(Tags::class, ['resource' => $this->application])
        ->call('addTag', (string) $foreignTag->id)
        ->assertNotFound();

    $attached = $this->application->tags()->get();
    expect($attached)->toHaveCount(1)
        ->and($attached->first()->id)->not->toBe($foreignTag->id)
        ->and($attached->first()->team_id)->toBe($this->teamA->id);
});

test('deploy by tag skips resources of another team', function () {
    Queue::fake();
    $tag = Tag::create(['name' => 'production', 'team_id' => $this->teamB->id]);
    $this->application->tags()->attach($tag->id);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'team-b-deploy',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['deploy'],
        'team_id' => $this->teamB->id,
    ]);
    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->postJson('/api/v1/deploy', ['tag' => 'production'])
        ->assertOk()
        ->assertJsonMissingPath('details');

    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('scheduled task belongs to the resource team after a team switch', function () {
    $component = Livewire::test(AddScheduledTask::class, [
        'id' => (string) $this->application->id,
        'type' => 'application',
        'containerNames' => collect(),
    ]);
    session(['currentTeam' => $this->teamB]);

    $component->set('name', 'team-switch-task')
        ->set('command', 'echo ok')
        ->set('frequency', '0 0 * * *')
        ->call('submit')
        ->assertHasNoErrors();

    expect(ScheduledTask::query()->sole()->team_id)->toBe($this->teamA->id);
});

test('service backup schedule offers only S3 storages of the service team', function () {
    $ownS3 = createS3ForTeamSwitchTest($this->teamA, 'team-a-s3');
    createS3ForTeamSwitchTest($this->teamB, 'team-b-s3');
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $ownS3->id,
        'database_id' => $this->serviceDatabase->id,
        'database_type' => $this->serviceDatabase->getMorphClass(),
    ]);
    $component = Livewire::test(ServiceVolumeBackupIndex::class, ['service' => $this->service]);
    session(['currentTeam' => $this->teamB]);

    $component->call('openSchedule', $backup->uuid)
        ->assertSet('s3s', fn ($s3s) => $s3s->pluck('id')->all() === [$ownS3->id])
        ->assertSee('S3 storage: team-a-s3 (bucket: team-a-s3-bucket)');
});

test('backup edit keeps the S3 storage of the backup team when given a foreign list', function () {
    $ownS3 = createS3ForTeamSwitchTest($this->teamA, 'team-a-s3');
    $foreignS3 = createS3ForTeamSwitchTest($this->teamB, 'team-b-s3');
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => $ownS3->id,
        'database_id' => $this->serviceDatabase->id,
        'database_type' => $this->serviceDatabase->getMorphClass(),
    ]);

    $component = Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => $this->teamB->s3s])
        ->call('instantSave');
    expect($backup->fresh()->s3_storage_id)->toBe($ownS3->id)
        ->and($backup->fresh()->save_s3)->toBeTruthy();

    $component->set('s3StorageIds', [$foreignS3->id])->call('instantSave');
    expect($backup->fresh()->s3_storage_id)->toBe($ownS3->id);
});

test('service backup executions label the S3 storage of the service team after a team switch', function () {
    $ownS3 = createS3ForTeamSwitchTest($this->teamA, 'team-a-s3');
    $schedule = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $ownS3->id,
        'database_id' => $this->serviceDatabase->id,
        'database_type' => $this->serviceDatabase->getMorphClass(),
    ]);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $schedule->id, 'status' => 'success']);
    $component = Livewire::test(BackupExecutions::class, ['service' => $this->service]);
    session(['currentTeam' => $this->teamB]);

    $component->call('$refresh')
        ->assertViewHas('executions', fn ($executions) => str_contains($executions->first()['s3_tooltip'], 'team-a-s3'));
});
