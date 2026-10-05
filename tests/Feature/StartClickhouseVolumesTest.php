<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Jobs\DatabaseStartJob;
use App\Livewire\Project\Database\Clickhouse\General as ClickhouseGeneral;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $this->team = $team;
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $team->members()->attach($this->owner->id, ['role' => 'owner']);
    $team->members()->attach($this->member->id, ['role' => 'member']);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @return array<string, mixed>
 */
function startedClickhouseCompose(): array
{
    $compose = null;
    Process::assertRan(function ($process) use (&$compose) {
        if (preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| (?:sudo )?tee \S+\/docker-compose\.yml/", $process->command, $matches)) {
            $compose = Yaml::parse(base64_decode($matches[1]));
        }

        return true;
    });

    return $compose;
}

it('keeps the data volume when a file mount is added', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    LocalFileVolume::create([
        'fs_path' => database_configuration_dir().'/'.$database->uuid.'/etc/clickhouse-server/users.d/extra.xml',
        'mount_path' => '/etc/clickhouse-server/users.d/extra.xml',
        'content' => '<clickhouse></clickhouse>',
        'is_directory' => false,
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    StartClickhouse::run($database->fresh(), activity()->log('Starting database'));

    expect(startedClickhouseCompose()['services'][$database->uuid]['volumes'])->toBe([
        "clickhouse-data-{$database->uuid}:/var/lib/clickhouse",
        database_configuration_dir()."/{$database->uuid}/etc/clickhouse-server/users.d/extra.xml:/etc/clickhouse-server/users.d/extra.xml",
    ]);
});

/**
 * Fake the remote `docker inspect` so the container's data directory is mounted from $volumeName.
 */
function fakeClickhouseDataMount(string $volumeName): void
{
    Process::fake(fn ($process) => Process::result(
        output: str_contains($process->command, 'docker inspect') ? "volume {$volumeName}" : 'OK',
    ));
}

function ranRemoteCommandContaining(string $needle): bool
{
    $ran = false;
    Process::assertRan(function ($process) use ($needle, &$ran) {
        $ran = $ran || str_contains($process->command, $needle);

        return true;
    });

    return $ran;
}

it('blocks start and restart while the current data is in an unnamed volume', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount('3f1c0ffee');

    expect(StartDatabase::run($database->fresh()))->toContain('unnamed Docker volume 3f1c0ffee')
        ->and(RestartDatabase::run($database->fresh()))->toContain('unnamed Docker volume 3f1c0ffee')
        ->and(ranRemoteCommandContaining("docker rm -f {$database->uuid}"))->toBeFalse();
    Queue::assertNotPushed(DatabaseStartJob::class);
});

it('starts a database that uses its data volume', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount("clickhouse-data-{$database->uuid}");

    expect(StartDatabase::run($database->fresh()))->toBeInstanceOf(Activity::class);
    Queue::assertPushed(DatabaseStartJob::class);
});

it('keeps the container on stop so the unnamed volume stays linked, except on delete', function (bool $keepAnonymousDataVolume, bool $removesContainer) {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount('3f1c0ffee');

    StopDatabase::run($database->fresh(), dockerCleanup: false, keepAnonymousDataVolume: $keepAnonymousDataVolume);

    expect(ranRemoteCommandContaining("docker rm -f {$database->uuid}"))->toBe($removesContainer);
})->with([
    'stop' => [true, false],
    'delete' => [false, true],
]);

it('lets an admin keep the current data volume and warns about it', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount('3f1c0ffee');
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(ClickhouseGeneral::class, ['database' => $database->fresh()])
        ->assertSet('anonymousDataVolume', '3f1c0ffee')
        ->assertSee('Keep current data')
        ->call('keepCurrentDataVolume')
        ->assertSet('anonymousDataVolume', null);

    expect($database->persistentStorages()->where('mount_path', StandaloneClickhouse::DATA_DIRECTORY)->value('name'))->toBe('3f1c0ffee')
        ->and(StartDatabase::prerequisiteError($database->fresh()))->toBeNull();
});

it('does not let a member keep the current data volume', function () {
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount('3f1c0ffee');
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ClickhouseGeneral::class, ['database' => $database->fresh()])
        ->assertSee('Current data is not in the data volume')
        ->call('keepCurrentDataVolume');

    expect($database->persistentStorages()->where('mount_path', StandaloneClickhouse::DATA_DIRECTORY)->value('name'))
        ->toBe("clickhouse-data-{$database->uuid}");
});

it('reads the data mount with sudo on a non-root server', function () {
    $this->server->update(['user' => 'cooluser']);
    $database = create_standalone_clickhouse($this->environment->id, $this->destination);
    fakeClickhouseDataMount('3f1c0ffee');

    expect($database->fresh()->anonymousDataVolume())->toBe('3f1c0ffee')
        ->and(ranRemoteCommandContaining("sudo docker inspect --format '{{range .Mounts}}{{if eq .Destination \"/var/lib/clickhouse\"}}{{.Type}} {{.Name}}{{end}}{{end}}' '{$database->uuid}' 2>/dev/null || sudo true"))->toBeTrue();
});
