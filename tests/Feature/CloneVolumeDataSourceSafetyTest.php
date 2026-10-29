<?php

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\CloneMe;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Horizon\Tags;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const CLONE_SAFETY_SERVICE_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'web-data:/usr/share/nginx/html'
YAML;

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->service = Service::factory()->create([
        'docker_compose_raw' => CLONE_SAFETY_SERVICE_COMPOSE,
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->service->parse();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

/**
 * Runs the queued jobs with the database driver, so every chain step and the catch callback
 * are serialized and restored like on a Horizon worker.
 */
function cloneSafetyUseDatabaseQueue(): void
{
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['queue.default' => 'database']);
}

/**
 * The worker runs in the test process, so a high `--memory` keeps the default 128 MB limit from
 * stopping it after the first chain step when earlier tests have grown the process.
 */
function cloneSafetyWorkQueue(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--queue' => 'high', '--sleep' => 0, '--memory' => 4096]);
}

/** The volume copy (`cp -a` in a helper container) fails like on a full disk; every other remote command works. */
function cloneSafetyFailVolumeCopy(): void
{
    Process::fake(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'cp -a')
        ? Process::result(errorOutput: 'No space left on device', exitCode: 1)
        : Process::result(output: ''));
}

/** `docker ps` (running containers only) lists the given container names; every other remote command works. */
function cloneSafetyListRunningContainers(string ...$names): void
{
    Process::fake(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'docker ps --filter')
        ? Process::result(output: implode("\n", $names))
        : Process::result(output: ''));
}

function cloneSafetyMarkServiceRunning(Service $service): void
{
    $service->applications()->update(['status' => 'running:healthy']);
    $service->databases()->update(['status' => 'running:healthy']);
}

test('a failed volume copy starts the running source service again and notifies the team', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyFailVolumeCopy();
    cloneSafetyMarkServiceRunning($this->service);
    StopService::shouldRun()->once()->withArgs(fn (Service $service) => $service->is($this->service));
    StartService::shouldRun()->once()->withArgs(fn (Service $service) => $service->is($this->service));

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    expect(DB::table('failed_jobs')->count())->toBe(1);
    Notification::assertSentTo($this->team, GeneralNotification::class, function (GeneralNotification $notification) {
        return $notification->success === false
            && str_contains($notification->message, $this->service->name)
            && str_contains($notification->message, 'No space left on device');
    });
});

test('a volume copy of a stopped source service neither stops nor starts it', function () {
    cloneSafetyUseDatabaseQueue();
    StopService::shouldRun()->never();
    StartService::shouldRun()->never();

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    expect(DB::table('failed_jobs')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a successful volume copy stops the running source service and starts it again', function () {
    cloneSafetyUseDatabaseQueue();
    cloneSafetyMarkServiceRunning($this->service);
    StopService::shouldRun()->once();
    StartService::shouldRun()->once();

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    expect(DB::table('failed_jobs')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a failed stop that leaves some containers of the source service running starts only the stopped containers again', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyMarkServiceRunning($this->service);
    cloneSafetyListRunningContainers('web-'.$this->service->uuid);
    StopService::shouldRun()->once()->andReturn('docker stop timed out');
    StartService::shouldRun()->never();

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    $workdir = $this->service->workdir();
    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$this->service->uuid} up -d --no-recreate < /dev/null"));
    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), '--force-recreate'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => $notification->success === false
        && str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'started its stopped containers again')
        && str_contains($notification->message, 'docker stop timed out'));
});

test('a failed start of the stopped containers of a partly stopped source service is in the notification', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyMarkServiceRunning($this->service);
    Process::fake(fn (PendingProcess $process) => match (true) {
        str_contains(remoteProcessScript($process), 'docker ps --filter') => Process::result(output: 'web-'.$this->service->uuid),
        str_contains(remoteProcessScript($process), 'up -d --no-recreate') => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1),
        default => Process::result(output: ''),
    });
    StopService::shouldRun()->once()->andReturn(null);
    StartService::shouldRun()->never();

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'could not start its stopped containers again'));
});

test('a stop that leaves containers of the source service running copies no data', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyMarkServiceRunning($this->service);
    cloneSafetyListRunningContainers('web-'.$this->service->uuid);
    StopService::shouldRun()->once()->andReturn(null);
    StartService::shouldRun()->never();

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'web-'.$this->service->uuid));
});

test('a failed stop that left the source service down starts it again', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyMarkServiceRunning($this->service);
    StopService::shouldRun()->once()->andReturn('docker stop timed out');
    StartService::shouldRun()->once()->withArgs(fn (Service $service) => $service->is($this->service));

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    cloneSafetyWorkQueue();

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'started')
        && str_contains($notification->message, 'docker stop timed out'));
});

test('cloning volume data needs the permission to stop the source resource', function () {
    Bus::fake();
    Gate::before(fn ($user, string $ability) => $ability === 'stop' ? false : null);

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid);

    expect(Service::query()->count())->toBe(1);
    Bus::assertNotDispatched(CallQueuedClosure::class);

    Livewire::test(CloneMe::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('selectedDestination', $this->destination->uuid)
        ->set('newName', 'cloned-env')
        ->set('cloneVolumeData', true)
        ->call('clone', 'environment');

    expect(Service::query()->count())->toBe(1)
        ->and($this->project->environments()->where('name', 'cloned-env')->exists())->toBeFalse();
    Bus::assertNotDispatched(CallQueuedClosure::class);
});

test('cloning without volume data needs no stop permission and stops nothing', function () {
    Bus::fake();
    Gate::before(fn ($user, string $ability) => $ability === 'stop' ? false : null);

    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    expect(Service::query()->count())->toBe(2);
    Bus::assertNotDispatched(CallQueuedClosure::class);
    Bus::assertNotDispatched(VolumeCloneJob::class);
});

test('cloning a running database with volume data copies its volumes in one chain between stop and start', function (string $component) {
    Bus::fake();
    $database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
    ]);
    $database->forceFill(['status' => 'running:healthy'])->saveQuietly();
    LocalPersistentVolume::query()->where('resource_type', $database->getMorphClass())->where('resource_id', $database->id)->delete();
    foreach (['postgres-data-'.$database->uuid, $database->uuid.'-extra'] as $name) {
        LocalPersistentVolume::create([
            'name' => $name,
            'mount_path' => '/data/'.$name,
            'resource_id' => $database->id,
            'resource_type' => $database->getMorphClass(),
        ]);
    }
    $this->service->delete();

    if ($component === ResourceOperations::class) {
        Livewire::test(ResourceOperations::class, ['resource' => $database])
            ->set('cloneVolumeData', true)
            ->call('cloneTo', $this->destination->uuid)
            ->assertHasNoErrors();
    } else {
        Livewire::test(CloneMe::class, [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ])
            ->set('selectedDestination', $this->destination->uuid)
            ->set('newName', 'cloned-env')
            ->set('cloneVolumeData', true)
            ->call('clone', 'environment')
            ->assertHasNoErrors();
    }

    Bus::assertNotDispatched(VolumeCloneJob::class);
    Bus::assertChained([
        CallQueuedClosure::class,
        VolumeCloneJob::class,
        VolumeCloneJob::class,
        CallQueuedClosure::class,
    ]);
})->with([
    'resource operations' => [ResourceOperations::class],
    'environment clone' => [CloneMe::class],
]);

test('a restored same-server volume clone job has explicit Horizon tags', function () {
    $server = $this->destination->server;
    $volume = LocalPersistentVolume::create(['name' => 'clone-target', 'mount_path' => '/data', 'resource_id' => 1, 'resource_type' => Service::class]);

    // Without explicit tags, Horizon compares the job's models with `==`. Two restored copies of one
    // server fail that comparison on PHP 8.4 ("Nesting level too deep"), so the chain stops before the copy.
    $restoredJob = unserialize(serialize(new VolumeCloneJob('clone-source', 'clone-target', $server, $server, $volume)));

    expect(Tags::extractExplicitTags($restoredJob))->toBe([Server::class.':'.$server->id, LocalPersistentVolume::class.':'.$volume->id]);
});

function cloneSafetyCreateDatabase(object $test, string $status): StandalonePostgresql
{
    $database = StandalonePostgresql::create([
        'name' => 'api-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id, 'destination_type' => $test->destination->getMorphClass(),
    ]);
    $database->forceFill(['status' => $status])->saveQuietly();

    return $database;
}

function cloneSafetyApiHeaders(User $user, int $teamId): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'clone-safety',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $teamId,
    ]);
    auth()->forgetGuards();

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

function cloneSafetyCreateApplication(object $test, string $status): Application
{
    $application = Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
    ]);
    $application->forceFill(['status' => $status])->saveQuietly();
    LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    return $application;
}

test('a failed volume copy of a running database cloned through the API starts it again and notifies the team', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    cloneSafetyFailVolumeCopy();
    $database = cloneSafetyCreateDatabase($this, 'running:healthy');
    StopDatabase::shouldRun()->once()->withArgs(fn ($source) => $source->is($database));
    StartDatabase::shouldRun()->once()->withArgs(fn ($source) => $source->is($database));

    $this->withHeaders(cloneSafetyApiHeaders($this->user, $this->team->id))
        ->postJson("/api/v1/databases/{$database->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertCreated();

    cloneSafetyWorkQueue();

    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => $notification->success === false
        && str_contains($notification->message, 'api-db')
        && str_contains($notification->message, 'No space left on device'));
});

test('a failed stop of a running database cloned through the API copies no data and leaves it running', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    $database = cloneSafetyCreateDatabase($this, 'running:healthy');
    cloneSafetyListRunningContainers($database->uuid);
    StopDatabase::shouldRun()->once()->andReturn('Database stop failed: Connection refused');
    StartDatabase::shouldRun()->never();

    $this->withHeaders(cloneSafetyApiHeaders($this->user, $this->team->id))
        ->postJson("/api/v1/databases/{$database->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertCreated();

    cloneSafetyWorkQueue();

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'api-db')
        && str_contains($notification->message, 'Connection refused'));
});

test('cloning a running database through the API copies its volumes after a successful stop', function () {
    cloneSafetyUseDatabaseQueue();
    $database = cloneSafetyCreateDatabase($this, 'running:healthy');
    StopDatabase::shouldRun()->once()->andReturn('Database stopped successfully');
    StartDatabase::shouldRun()->once();

    $this->withHeaders(cloneSafetyApiHeaders($this->user, $this->team->id))
        ->postJson("/api/v1/databases/{$database->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertCreated();

    cloneSafetyWorkQueue();

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    expect(DB::table('failed_jobs')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('cloning a stopped database through the API copies its volumes without stopping or starting it', function () {
    Bus::fake();
    $database = cloneSafetyCreateDatabase($this, 'exited');

    $this->withHeaders(cloneSafetyApiHeaders($this->user, $this->team->id))
        ->postJson("/api/v1/databases/{$database->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertCreated();

    Bus::assertNotDispatched(CallQueuedClosure::class);
    Bus::assertDispatched(VolumeCloneJob::class);
});

/**
 * `docker ps` lists the running containers: before the stop the given ones, after it the remaining ones.
 *
 * @param  list<string>  $before
 * @param  list<string>  $after
 */
function cloneSafetyListRunningContainersBeforeAndAfterStop(array $before, array $after): void
{
    $listings = 0;
    Process::fake(function (PendingProcess $process) use (&$listings, $before, $after) {
        if (! str_contains(remoteProcessScript($process), 'docker ps --filter')) {
            return Process::result(output: '');
        }

        return Process::result(output: implode("\n", $listings++ === 0 ? $before : $after));
    });
}

function cloneSafetyCloneApplicationWithVolumes(object $test, Application $application): void
{
    $test->service->delete();
    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $test->destination->uuid)
        ->assertHasNoErrors();
    cloneSafetyWorkQueue();
}

test('a failed stop that took down some containers of the source application redeploys the commit that ran before', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    $application = cloneSafetyCreateApplication($this, 'running:healthy');
    $runningCommit = str_repeat('a', 40);
    foreach ([$runningCommit => now()->subHour(), str_repeat('b', 40) => now()->subDay()] as $commit => $updatedAt) {
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id, 'deployment_uuid' => new_public_id(), 'pull_request_id' => 0,
            'commit' => $commit, 'status' => 'finished', 'server_id' => $this->destination->server_id, 'destination_id' => $this->destination->id,
        ])->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }
    cloneSafetyListRunningContainersBeforeAndAfterStop(['web-'.$application->uuid, 'worker-'.$application->uuid], ['worker-'.$application->uuid]);
    StopApplication::shouldRun()->once()->andReturn('docker stop failed for worker');

    cloneSafetyCloneApplicationWithVolumes($this, $application);

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    $deployments = ApplicationDeploymentQueue::query()->where('application_id', $application->id)->orderBy('id')->get();
    expect($deployments)->toHaveCount(3);
    $redeploy = $deployments->last();
    expect($redeploy->commit)->toBe($runningCommit)
        ->and($redeploy->pull_request_id)->toBe(0);
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => $notification->success === false
        && str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, "started a redeploy of '{$application->name}'")
        && str_contains($notification->message, 'docker stop failed for worker'));
});

test('a failed stop that took down containers of a source application without a known commit redeploys its configured commit', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    $application = cloneSafetyCreateApplication($this, 'running:healthy');
    cloneSafetyListRunningContainersBeforeAndAfterStop(['web-'.$application->uuid, 'worker-'.$application->uuid], ['worker-'.$application->uuid]);
    StopApplication::shouldRun()->once()->andReturn(null);

    cloneSafetyCloneApplicationWithVolumes($this, $application);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->sole()->commit)->toBe('HEAD');
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, "started a redeploy of '{$application->name}'")
        && str_contains($notification->message, 'could not find the commit'));
});

test('a failed stop that took down no container of the source application does not redeploy it', function () {
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    cloneSafetyUseDatabaseQueue();
    $application = cloneSafetyCreateApplication($this, 'running:healthy');
    cloneSafetyListRunningContainersBeforeAndAfterStop(['web-'.$application->uuid], ['web-'.$application->uuid]);
    StopApplication::shouldRun()->once()->andReturn('Server is not functional');

    cloneSafetyCloneApplicationWithVolumes($this, $application);

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'cp -a'));
    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(0);
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'did not copy')
        && str_contains($notification->message, 'is still running'));
});

test('cloning a running application with volume data copies its volumes in one chain between stop and start', function () {
    Bus::fake();
    $application = cloneSafetyCreateApplication($this, 'running:healthy');
    $this->service->delete();

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    Bus::assertChained([CallQueuedClosure::class, VolumeCloneJob::class, CallQueuedClosure::class]);
    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('cloning a stopped application with volume data neither stops nor redeploys it', function () {
    Bus::fake();
    $application = cloneSafetyCreateApplication($this, 'exited');
    $this->service->delete();

    Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    Bus::assertNotDispatched(CallQueuedClosure::class);
    Bus::assertDispatched(VolumeCloneJob::class);
    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});
