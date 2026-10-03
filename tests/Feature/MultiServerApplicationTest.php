<?php

use App\Actions\Application\StopApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Database\Sqlite\ConnectApplication;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Notification::fake();
    config()->set('cache.default', 'array');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'deployment_success_email_notifications' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->mainServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'main-server']);
    $this->mainDestination = StandaloneDocker::where('server_id', $this->mainServer->id)->firstOrFail();
    $this->extraServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'extra-server']);
    $this->extraDestination = StandaloneDocker::where('server_id', $this->extraServer->id)->firstOrFail();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->mainDestination->id,
        'destination_type' => $this->mainDestination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $this->application->additional_networks()->attach($this->extraDestination->id, ['server_id' => $this->extraServer->id]);
    $this->application->refresh();
});

/**
 * @param  array<string, mixed>  $properties
 */
function makeMultiServerDeploymentJob(array $properties): array
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    foreach (['pull_request_id' => 0, 'preview' => null, 'only_this_server' => false, 'commit' => 'HEAD', 'rollback' => false, ...$properties] as $name => $value) {
        $reflection->getProperty($name)->setValue($job, $value);
    }

    return [$job, $reflection];
}

function callMultiServerDeploymentJob(array $jobAndReflection, string $method, mixed ...$arguments): mixed
{
    [$job, $reflection] = $jobAndReflection;

    return $reflection->getMethod($method)->invoke($job, ...$arguments);
}

function createMultiServerDeployment(Application $application, Server $server, string $status, ?string $parentDeploymentUuid = null): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'server_id' => $server->id,
        'status' => $status,
        'parent_deployment_uuid' => $parentDeploymentUuid,
    ]);
}

describe('registry image guard', function () {
    test('fails a multi-server deployment without a registry image', function () {
        $job = makeMultiServerDeploymentJob(['application' => $this->application]);

        expect(fn () => callMultiServerDeploymentJob($job, 'ensureRegistryImageForMultipleServers'))
            ->toThrow(DeploymentException::class, 'Before deploying to multiple servers');
    });

    test('allows a multi-server deployment with a registry image', function () {
        $this->application->update(['docker_registry_image_name' => 'registry.example.com/app']);
        $job = makeMultiServerDeploymentJob(['application' => $this->application->fresh()]);

        callMultiServerDeploymentJob($job, 'ensureRegistryImageForMultipleServers');
    })->throwsNoExceptions();

    test('allows preview deployments, which run only on the main server', function () {
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'pull_request_id' => 7]);

        callMultiServerDeploymentJob($job, 'ensureRegistryImageForMultipleServers');
    })->throwsNoExceptions();
});

function makeVolumeWarningJob(Application $application, array &$logEntries): array
{
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout') use (&$logEntries) {
        $logEntries[] = [$message, $type];
    });

    return makeMultiServerDeploymentJob(['application' => $application, 'application_deployment_queue' => $queue]);
}

describe('volume warning', function () {
    test('warns in the deployment log when an application with a volume uses multiple servers', function () {
        LocalPersistentVolume::create([
            'name' => 'app-data-'.$this->application->uuid,
            'mount_path' => '/data',
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
        ]);
        $logEntries = [];
        $job = makeVolumeWarningJob($this->application->fresh(), $logEntries);

        callMultiServerDeploymentJob($job, 'warnAboutVolumesOnMultipleServers');

        expect($logEntries)->toBe([[
            'Warning: This application uses multiple servers and has persistent storage. Volumes are not shared between servers, so each server has its own data.',
            'stderr',
        ]]);
    });

    test('does not warn when the application has no volume', function () {
        $logEntries = [];
        $job = makeVolumeWarningJob($this->application, $logEntries);

        callMultiServerDeploymentJob($job, 'warnAboutVolumesOnMultipleServers');

        expect($logEntries)->toBe([]);
    });
});

describe('mixed proxy warning', function () {
    test('warns in the deployment log when an additional server uses another proxy', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->extraServer->save();
        $logEntries = [];
        $job = makeVolumeWarningJob($this->application->fresh(), $logEntries);

        callMultiServerDeploymentJob($job, 'warnAboutMixedProxiesOnMultipleServers');

        expect($logEntries)->toBe([[
            'Warning: The primary server uses TRAEFIK and extra-server uses CADDY. All servers of an application must use the same proxy, because they share the same proxy labels. The domains of this application will not work through the proxy on extra-server.',
            'stderr',
        ]]);
    });

    test('does not warn when all servers use the same proxy', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->extraServer->save();
        $logEntries = [];
        $job = makeVolumeWarningJob($this->application->fresh(), $logEntries);

        callMultiServerDeploymentJob($job, 'warnAboutMixedProxiesOnMultipleServers');

        expect($logEntries)->toBe([]);
    });
});

describe('combined deployment notification', function () {
    test('links queued additional deployments to the main deployment', function () {
        $parent = createMultiServerDeployment($this->application, $this->mainServer, ApplicationDeploymentStatus::FINISHED->value);
        $job = makeMultiServerDeploymentJob([
            'application' => $this->application,
            'application_deployment_queue' => $parent,
            'deployment_uuid' => $parent->deployment_uuid,
            'server' => $this->mainServer,
            'mainServer' => $this->mainServer,
            'destination' => $this->mainDestination,
        ]);

        expect(callMultiServerDeploymentJob($job, 'deploy_to_additional_destinations'))->toBe(1);

        $child = ApplicationDeploymentQueue::where('server_id', $this->extraServer->id)->sole();
        expect($child->parent_deployment_uuid)->toBe($parent->deployment_uuid);
    });

    test('additional servers deploy the same commit and image tag as a rollback on the main server', function () {
        $parent = ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => new_public_id(),
            'server_id' => $this->mainServer->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'commit' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
            'rollback' => true,
            'docker_registry_image_tag' => 'v1.2.3',
        ]);
        $job = makeMultiServerDeploymentJob([
            'application' => $this->application,
            'application_deployment_queue' => $parent,
            'deployment_uuid' => $parent->deployment_uuid,
            'server' => $this->mainServer,
            'mainServer' => $this->mainServer,
            'destination' => $this->mainDestination,
            'commit' => $parent->commit,
            'rollback' => true,
        ]);

        callMultiServerDeploymentJob($job, 'deploy_to_additional_destinations');

        $child = ApplicationDeploymentQueue::where('server_id', $this->extraServer->id)->sole();
        expect($child->commit)->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678')
            ->and((bool) $child->rollback)->toBeTrue()
            ->and($child->docker_registry_image_tag)->toBe('v1.2.3');
    });

    test('does not notify while an additional server is still deploying', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        $failed = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FAILED->value, $parentUuid);
        createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::IN_PROGRESS->value, $parentUuid);
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $failed]);

        callMultiServerDeploymentJob($job, 'handleFailedDeployment');

        Notification::assertNothingSent();
    });

    test('does not notify before the main deployment queued all additional servers', function () {
        $parentUuid = new_public_id();
        $finished = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $finished]);

        callMultiServerDeploymentJob($job, 'sendMultiServerDeploymentNotification', $parentUuid);

        Notification::assertNothingSent();
    });

    test('sends one success notification after all additional servers succeed', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        $first = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $first]);

        callMultiServerDeploymentJob($job, 'sendMultiServerDeploymentNotification', $parentUuid);
        callMultiServerDeploymentJob($job, 'sendMultiServerDeploymentNotification', $parentUuid);

        Notification::assertSentToTimes($this->team, DeploymentSuccess::class, 1);
        Notification::assertSentTo($this->team, DeploymentSuccess::class, fn (DeploymentSuccess $notification) => $notification->deployment_uuid === $parentUuid);
        Notification::assertNotSentTo($this->team, DeploymentFailed::class);
    });

    test('sends the summary when the last additional server deployment is cancelled while running', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        $cancelled = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::CANCELLED_BY_USER->value, $parentUuid);
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $cancelled]);

        expect(fn () => callMultiServerDeploymentJob($job, 'failDeployment'))
            ->toThrow(DeploymentException::class, 'Deployment cancelled by user');

        Notification::assertSentToTimes($this->team, DeploymentFailed::class, 1);
        Notification::assertSentTo($this->team, DeploymentFailed::class, fn (DeploymentFailed $notification) => $notification->deployment_uuid === $cancelled->deployment_uuid);
        Notification::assertNotSentTo($this->team, DeploymentSuccess::class);
    });

    test('sends the summary when the last additional server deployment is cancelled before it starts', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        $cancelled = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::CANCELLED_BY_USER->value, $parentUuid);
        [$job] = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $cancelled]);

        $job->handle();

        Notification::assertSentToTimes($this->team, DeploymentFailed::class, 1);
        Notification::assertSentTo($this->team, DeploymentFailed::class, fn (DeploymentFailed $notification) => $notification->deployment_uuid === $cancelled->deployment_uuid);
    });

    test('does not notify when a cancelled additional server deployment is not the last one', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::IN_PROGRESS->value, $parentUuid);
        $cancelled = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::CANCELLED_BY_USER->value, $parentUuid);
        [$job] = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $cancelled]);

        $job->handle();

        Notification::assertNothingSent();
    });

    test('sends a failed notification that links to the failed server deployment', function () {
        $parentUuid = new_public_id();
        Cache::put("multi-server-deployment-queued:{$parentUuid}", true);
        $finished = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FINISHED->value, $parentUuid);
        $failed = createMultiServerDeployment($this->application, $this->extraServer, ApplicationDeploymentStatus::FAILED->value, $parentUuid);
        $job = makeMultiServerDeploymentJob(['application' => $this->application, 'application_deployment_queue' => $finished]);

        callMultiServerDeploymentJob($job, 'sendMultiServerDeploymentNotification', $parentUuid);

        Notification::assertSentTo($this->team, DeploymentFailed::class, fn (DeploymentFailed $notification) => $notification->deployment_uuid === $failed->deployment_uuid);
        Notification::assertNotSentTo($this->team, DeploymentSuccess::class);
    });
});

/**
 * Records the deployment commands instead of running them on a server.
 */
class MultiServerRecordingDeploymentJob extends ApplicationDeploymentJob
{
    /** @var list<string> */
    public array $recordedCommands = [];

    public function __construct() {}

    /** Output that `docker images -q` returns; empty means the image is missing. */
    public string $localImageOutput = 'sha256:0123456789ab';

    public bool $failImagePull = false;

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $remoteCommand = $command['command'] ?? $command[0];
            $this->recordedCommands[] = $remoteCommand;
            if ($this->failImagePull && str_starts_with($remoteCommand, 'docker pull ') && ! ($command['ignore_errors'] ?? false)) {
                throw new RuntimeException('Registry image pull failed.');
            }
            if (isset($command['save'])) {
                (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this)->put($command['save'], $this->localImageOutput);
            }
        }
    }
}

function makeInlineDockerfileJob(Application $application, bool $isAdditionalServer, ?Server $server = null): array
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = new MultiServerRecordingDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry');
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'is_this_additional_server' => $isAdditionalServer,
        'pull_request_id' => 0,
        'saved_outputs' => collect(),
        'server' => $server ?? $application->destination->server,
    ] as $name => $value) {
        $reflection->getProperty($name)->setValue($job, $value);
    }
    $reflection->getMethod('generate_image_names')->invoke($job);

    return [$job, $reflection];
}

describe('inline Dockerfile image on additional servers', function () {
    beforeEach(function () {
        $this->application->update([
            'dockerfile' => "FROM nginx:alpine\n",
            'docker_registry_image_name' => 'registry.example.com/nginx-multi',
        ]);
    });

    test('an additional server pulls the image that the main server pushed', function () {
        [$job, $reflection] = makeInlineDockerfileJob($this->application->fresh(), true);

        expect($reflection->getMethod('pull_image_for_additional_server')->invoke($job))->toBeTrue()
            ->and($job->recordedCommands)->toBe([
                "docker pull 'registry.example.com/nginx-multi:latest'",
                "docker images -q 'registry.example.com/nginx-multi:latest' 2>/dev/null",
            ]);
    });

    test('an additional server fails when pulling the image fails even if an old image is cached', function () {
        [$job, $reflection] = makeInlineDockerfileJob($this->application->fresh(), true);
        $job->failImagePull = true;
        $job->localImageOutput = 'sha256:stale-cached-image';

        expect(fn () => $reflection->getMethod('pull_image_for_additional_server')->invoke($job))
            ->toThrow(RuntimeException::class, 'Registry image pull failed.');
        expect($job->recordedCommands)->toBe([
            "docker pull 'registry.example.com/nginx-multi:latest'",
        ]);
    });

    test('a deployments only server explains that the main server must push a missing image', function () {
        $this->extraServer->settings->update(['server_role' => 'deployment']);
        [$job, $reflection] = makeInlineDockerfileJob($this->application->fresh(), true, $this->extraServer->fresh());
        $job->localImageOutput = '';

        expect(fn () => $reflection->getMethod('pull_image_for_additional_server')->invoke($job))
            ->toThrow(DeploymentException::class, 'Image (registry.example.com/nginx-multi:latest) not found in the registry; the main server must push it first. The server (extra-server) is set to deployments only and cannot build it.');
    });

    test('the main server builds the image', function () {
        [$job, $reflection] = makeInlineDockerfileJob($this->application->fresh(), false);

        expect($reflection->getMethod('pull_image_for_additional_server')->invoke($job))->toBeFalse()
            ->and($job->recordedCommands)->toBe([]);
    });
});

test('stopping continues on the other servers when one server is not functional', function () {
    $this->mainServer->settings->update(['is_reachable' => false]);
    $this->extraServer->settings->update(['is_reachable' => false]);

    $result = StopApplication::run($this->application->fresh());

    expect($result)->toContain('main-server')->toContain('extra-server');
});

test('file storages of an application belong to all of its servers', function () {
    $file = LocalFileVolume::create([
        'fs_path' => '/data/coolify/applications/'.$this->application->uuid.'/config.yml',
        'mount_path' => '/app/config.yml',
        'content' => 'key: value',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    expect($file->servers()->pluck('id')->all())->toBe([$this->mainServer->id, $this->extraServer->id]);
});

describe('adding a server in the UI', function () {
    beforeEach(function () {
        $this->application->additional_networks()->detach();
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);
    });

    test('rejects an application with persistent storage', function () {
        LocalPersistentVolume::create([
            'name' => 'app-data-'.$this->application->uuid,
            'mount_path' => '/data',
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
        ]);

        Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
            ->call('addServer', $this->extraDestination->id, $this->extraServer->id)
            ->assertDispatched('error');

        expect($this->application->fresh()->additional_networks)->toHaveCount(0);
    });

    test('adds a server to an application without persistent storage', function () {
        Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
            ->call('addServer', $this->extraDestination->id, $this->extraServer->id);

        expect($this->application->fresh()->additional_networks)->toHaveCount(1);
    });

    test('rejects a server that uses a different proxy than the primary server', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->extraServer->save();

        Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
            ->call('addServer', $this->extraDestination->id, $this->extraServer->id)
            ->assertDispatched('error');

        expect($this->application->fresh()->additional_networks)->toHaveCount(0);
    });

    test('adds a server without a proxy next to a primary server with a proxy', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::NONE->value);
        $this->extraServer->save();

        Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
            ->call('addServer', $this->extraDestination->id, $this->extraServer->id);

        expect($this->application->fresh()->additional_networks)->toHaveCount(1);
    });
});

describe('adding a server through the API', function () {
    beforeEach(function () {
        $this->application->additional_networks()->detach();
        config(['app.maintenance.store' => 'array']);
        InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
        session(['currentTeam' => $this->team]);
        $this->token = $this->user->createToken('test-token', ['*']);
    });

    test('rejects a server that uses a different proxy than the primary server', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $this->extraServer->save();

        $this->withHeader('Authorization', 'Bearer '.$this->token->plainTextToken)
            ->postJson("/api/v1/applications/{$this->application->uuid}/destinations", [
                'destination_uuid' => $this->extraDestination->uuid,
            ])
            ->assertStatus(422);

        expect($this->application->fresh()->additional_networks)->toHaveCount(0);
    });

    test('adds a server that uses the same proxy as the primary server', function () {
        $this->mainServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->mainServer->save();
        $this->extraServer->proxy->set('type', ProxyTypes::CADDY->value);
        $this->extraServer->save();

        $this->withHeader('Authorization', 'Bearer '.$this->token->plainTextToken)
            ->postJson("/api/v1/applications/{$this->application->uuid}/destinations", [
                'destination_uuid' => $this->extraDestination->uuid,
            ])
            ->assertStatus(201);

        expect($this->application->fresh()->additional_networks)->toHaveCount(1);
    });
});

describe('caddy network label on additional servers', function () {
    test('uses the network of the server that is deployed', function () {
        $this->extraDestination->update(['network' => 'extra-network']);
        $labels = collect([
            "caddy_ingress_network={$this->mainDestination->network}",
            'caddy_0=app.example.com',
        ]);

        $job = makeMultiServerDeploymentJob([
            'application' => $this->application,
            'destination' => $this->extraDestination->fresh(),
        ]);

        expect(callMultiServerDeploymentJob($job, 'useDestinationNetworkInCaddyLabels', $labels)->all())->toBe([
            'caddy_ingress_network=extra-network',
            'caddy_0=app.example.com',
        ]);
    });

    test('keeps the labels on the primary server', function () {
        $labels = collect(["caddy_ingress_network={$this->mainDestination->network}"]);

        $job = makeMultiServerDeploymentJob([
            'application' => $this->application,
            'destination' => $this->mainDestination,
        ]);

        expect(callMultiServerDeploymentJob($job, 'useDestinationNetworkInCaddyLabels', $labels)->all())
            ->toBe(["caddy_ingress_network={$this->mainDestination->network}"]);
    });
});

describe('adding a volume to an application with additional servers', function () {
    beforeEach(function () {
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);
    });

    test('the storage page rejects the volume', function () {
        Livewire::test(Storage::class, ['resource' => $this->application->fresh()])
            ->assertSee('Volume mounts are unavailable')
            ->set('name', 'data')
            ->set('mount_path', '/data')
            ->call('submitPersistentVolume')
            ->assertDispatched('error');

        expect($this->application->persistentStorages()->count())->toBe(0);
    });

    test('connecting a SQLite database rejects the volume', function () {
        $sqlite = StandaloneSqlite::create([
            'name' => 'app-sqlite',
            'environment_id' => $this->application->environment_id,
            'destination_id' => $this->mainDestination->id,
            'destination_type' => $this->mainDestination->getMorphClass(),
        ]);

        Livewire::test(ConnectApplication::class, ['database' => $sqlite])
            ->set('applicationUuid', $this->application->uuid)
            ->set('mountPath', '/app/database')
            ->call('connect')
            ->assertNoRedirect();

        expect($this->application->persistentStorages()->count())->toBe(0);
    });

    test('an application on one server can still add a volume', function () {
        $this->application->additional_networks()->detach();

        Livewire::test(Storage::class, ['resource' => $this->application->fresh()])
            ->set('name', 'data')
            ->set('mount_path', '/data')
            ->call('submitPersistentVolume')
            ->assertNotDispatched('error')
            ->assertDontSee('Volume mounts are unavailable');

        expect($this->application->persistentStorages()->count())->toBe(1);
    });
});
