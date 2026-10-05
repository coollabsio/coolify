<?php

use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->teamA, ['role' => 'member']);
    $this->user->teams()->attach($this->teamB, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->teamA->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->service = Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    $this->serviceDatabase = ServiceDatabase::create([
        'service_id' => $this->service->id,
        'name' => 'zz-s3-list-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);
    $this->serviceApplication = ServiceApplication::create([
        'service_id' => $this->service->id,
        'name' => 'zz-s3-list-app',
        'image' => 'nginx:alpine',
    ]);
    $this->postgres = StandalonePostgresql::create([
        'name' => 'zz-s3-list-pg',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->ownS3 = createS3ForResourceTeamListTest($this->teamA, 'team-a-s3');
    createS3ForResourceTeamListTest($this->teamA, 'team-a-unusable-s3', false);
    createS3ForResourceTeamListTest($this->teamB, 'team-b-s3');

    $this->actingAs($this->user);
    session(['currentTeam' => $this->teamA]);
});

function createS3ForResourceTeamListTest(Team $team, string $name, bool $isUsable = true): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => $name.'-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => $isUsable,
        'team_id' => $team->id,
    ]);
}

function switchSessionTeamForResourceTeamListTest(Team $team): void
{
    session(['currentTeam' => $team]);
    Cache::flush();
}

test('embedded service database offers only S3 storages of the service team', function (string $sessionTeam) {
    switchSessionTeamForResourceTeamListTest($this->{$sessionTeam});

    Livewire::test(ServiceIndex::class, ['serviceApplication' => $this->serviceDatabase->fresh(), 'embedded' => true])
        ->assertSet('s3s', fn ($s3s) => $s3s->pluck('id')->all() === [$this->ownS3->id]);
})->with(['same session team' => 'teamA', 'after a team switch' => 'teamB']);

test('embedded service application offers only S3 storages of the service team after a team switch', function () {
    switchSessionTeamForResourceTeamListTest($this->teamB);

    Livewire::test(ServiceIndex::class, ['serviceApplication' => $this->serviceApplication->fresh(), 'embedded' => true])
        ->assertSet('s3s', fn ($s3s) => $s3s->pluck('id')->all() === [$this->ownS3->id]);
});

test('database backup page offers only usable S3 storages of the database team', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => '0 0 * * *',
        'database_id' => $this->postgres->id,
        'database_type' => $this->postgres->getMorphClass(),
    ]);
    $url = route('project.database.backup.s3', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $this->postgres->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    $this->get($url)
        ->assertOk()
        ->assertSee('team-a-s3')
        ->assertDontSee('team-a-unusable-s3')
        ->assertDontSee('team-b-s3');

    switchSessionTeamForResourceTeamListTest($this->teamB);
    $this->get($url)->assertRedirect(route('dashboard'));
});
