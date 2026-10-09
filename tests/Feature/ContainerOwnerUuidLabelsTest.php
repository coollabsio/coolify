<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\CleanupOrphanedPreviewContainersJob;
use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited',
    ]);
});

describe('label producers', function () {
    test('application and service labels carry UUIDs, not numeric ids', function () {
        $labels = defaultLabels('app-uuid', 'app-uuid', 'Project', 'Resource', 'production');
        $serviceLabels = defaultLabels('service-uuid', 'web-service-uuid', 'Project', 'Resource', 'production', type: 'service', subType: 'application', subUuid: 'part-uuid', subName: 'web');

        expect($labels)->toContain('coolify.applicationUuid=app-uuid')
            ->and($labels->filter(fn (string $label) => str_starts_with($label, 'coolify.applicationId=')))->toBeEmpty()
            ->and($serviceLabels)->toContain('coolify.serviceUuid=service-uuid')
            ->toContain('coolify.service.subUuid=part-uuid')
            ->toContain('coolify.service.subType=application')
            ->and($serviceLabels->filter(fn (string $label) => preg_match('/^coolify\.(serviceId|service\.subId)=/', $label)))->toBeEmpty();
    });

    test('database labels carry the database UUID', function () {
        $database = StandalonePostgresql::create([
            'name' => 'db',
            'postgres_password' => 'secret',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $labels = defaultDatabaseLabels($database);

        expect($labels)->toContain("coolify.databaseUuid={$database->uuid}")
            ->and($labels->filter(fn (string $label) => str_starts_with($label, 'coolify.databaseId=')))->toBeEmpty();
    });
});

describe('container lookups for services and databases', function () {
    test('database and service lookups match the UUID label and the compose project, never a numeric id', function () {
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
        $this->server->update(['private_key_id' => $privateKey->id]);
        $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        $database = StandalonePostgresql::create([
            'name' => 'db',
            'postgres_password' => 'secret',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $commands = collect();
        Process::fake(function ($process) use ($commands) {
            $commands->push($process->command);

            return Process::result(output: '');
        });

        getCurrentDatabaseContainerStatus($this->server->fresh(), $database);
        getCurrentServiceContainerStatus($this->server->fresh(), $service);

        expect($commands->implode("\n"))
            ->toContain("label=coolify.databaseUuid={$database->uuid}")
            ->toContain("label=com.docker.compose.project={$database->uuid}")
            ->not->toContain("label=coolify.databaseId={$database->id}")
            ->toContain("label=coolify.serviceUuid={$service->uuid}")
            ->toContain("label=com.docker.compose.project={$service->uuid}")
            ->not->toContain("label=coolify.serviceId={$service->id}");
    });

    test('service part containers are matched by UUID label, then compose service name', function () {
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $part = ServiceApplication::create(['service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);
        $services = collect([$service->fresh()]);
        $partOf = fn (array $labels) => resolveServiceContainerOwner($services, $labels)[2]?->id;

        expect($partOf(['coolify.serviceUuid' => $service->uuid, 'coolify.service.subType' => 'application', 'coolify.service.subUuid' => $part->uuid]))->toBe($part->id)
            // Moved from another instance: foreign ids, but compose project and service name still match.
            ->and($partOf(['coolify.serviceId' => '999', 'coolify.service.subType' => 'application', 'coolify.service.subId' => '999', 'com.docker.compose.project' => $service->uuid, 'com.docker.compose.service' => 'web']))->toBe($part->id)
            // Another instance's container with the same numeric ids: not ours.
            ->and($partOf(['coolify.serviceId' => (string) $service->id, 'coolify.service.subType' => 'application', 'coolify.service.subId' => (string) $part->id, 'com.docker.compose.project' => 'other']))->toBeNull();
    });
});

describe('owner resolution', function () {
    test('owner UUID comes from the UUID label, or from the compose project or stack for older containers', function (array $labels, ?string $expected) {
        expect(containerOwnerUuid($labels, 'application'))->toBe($expected);
    })->with([
        'uuid label' => [['coolify.applicationUuid' => 'app-uuid', 'com.docker.compose.project' => 'other'], 'app-uuid'],
        'legacy id + compose project' => [['coolify.applicationId' => '78', 'com.docker.compose.project' => 'app-uuid'], 'app-uuid'],
        'legacy id + swarm stack' => [['coolify.applicationId' => '78', 'com.docker.stack.namespace' => 'app-uuid'], 'app-uuid'],
        'legacy id without project' => [['coolify.applicationId' => '78'], null],
        'not an application container' => [['coolify.serviceUuid' => 'svc', 'com.docker.compose.project' => 'svc'], null],
    ]);

    test('owner UUID is read from the raw docker label string', function () {
        expect(containerOwnerUuid('coolify.applicationId=78,com.docker.compose.project=app-uuid,coolify.pullRequestId=3', 'application'))
            ->toBe('app-uuid');
    });

    test('a local numeric id never makes another instance\'s container ours', function () {
        $applications = collect([$this->application]);
        $localId = (string) $this->application->id;

        expect(resolveContainerOwner($applications, ['coolify.applicationId' => $localId, 'com.docker.compose.project' => 'other-instance-uuid'], 'application'))->toBeNull()
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => $localId], 'application'))->toBeNull()
            ->and(resolveContainerOwner($applications, ['coolify.applicationUuid' => 'unknown', 'coolify.applicationId' => $localId], 'application'))->toBeNull()
            ->and(resolveContainerApplicationId($applications, ['coolify.applicationId' => $localId, 'com.docker.compose.project' => 'other-instance-uuid']))->toBeNull()
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => '999999', 'com.docker.compose.project' => $this->application->uuid], 'application')?->id)->toBe($this->application->id)
            // Deployed before mid-2024: compose project was the deployment directory, the compose service starts with the UUID.
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => '999999', 'com.docker.compose.project' => 'deployment-dir', 'com.docker.compose.service' => $this->application->uuid.'-104512123456'], 'application')?->id)->toBe($this->application->id)
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => $localId, 'com.docker.compose.project' => 'deployment-dir', 'com.docker.compose.service' => 'other-instance-uuid-104512123456'], 'application'))->toBeNull()
            // Compose application with a custom start command: the compose project is the deployment directory, the container name is `{service}-{uuid}-{suffix}`.
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => '999999', 'com.docker.compose.project' => 'deployment-dir', 'com.docker.compose.service' => 'public-api', 'coolify.name' => 'public-api-'.$this->application->uuid.'-104512123456'], 'application')?->id)->toBe($this->application->id)
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => $localId, 'com.docker.compose.project' => 'deployment-dir', 'com.docker.compose.service' => 'public-api', 'coolify.name' => 'public-api-other-instance-uuid-104512123456'], 'application'))->toBeNull()
            ->and(resolveContainerOwner($applications, ['coolify.applicationId' => $localId, 'com.docker.compose.project' => 'deployment-dir', 'coolify.name' => 'public-api-'.$this->application->uuid.'x-104512123456'], 'application'))->toBeNull()
            ->and(implode("\n", dockerPsByOwnerCommands('application', 'app-uuid')))->not->toContain('label=coolify.applicationId=');
    });

    test('docker ps commands match the UUID label and the compose project of older containers', function () {
        $commands = dockerPsByOwnerCommands('application', 'app-uuid', ['label=coolify.pullRequestId=0']);

        expect($commands)->toHaveCount(3)
            ->and($commands[0])->toContain("--filter 'label=coolify.applicationUuid=app-uuid'")
            ->toContain("--filter 'label=coolify.pullRequestId=0'")
            ->and($commands[1])->toContain("--filter 'label=coolify.applicationId'")
            ->toContain("--filter 'label=com.docker.compose.project=app-uuid'")
            ->toContain("--filter 'label=coolify.pullRequestId=0'")
            // Compose project is the deployment directory: the container name contains the application UUID.
            ->and($commands[2])->toContain("--filter 'label=coolify.applicationId'")
            ->toContain("--filter 'name=app-uuid'")
            ->toContain("--filter 'label=coolify.pullRequestId=0'")
            ->and(dockerPsByOwnerCommands('service', 'service-uuid'))->toHaveCount(2);
    });
});

describe('status updates after a server transfer', function () {
    test('Sentinel push matches an older application container by compose project, not by its foreign id', function () {
        $otherApplication = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $job = new PushServerUpdateJob($this->server, ['containers' => [[
            'name' => $this->application->uuid.'-20260928T212126',
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => [
                'coolify.managed' => 'true',
                // The source instance id of this container equals another application here.
                'coolify.applicationId' => (string) $otherApplication->id,
                'coolify.pullRequestId' => '0',
                'com.docker.compose.project' => $this->application->uuid,
                'com.docker.compose.service' => $this->application->uuid.'-20260928T212126',
            ],
        ]]]);
        $job->handle();

        expect($job->foundApplicationIds->all())->toContain((string) $this->application->id)
            ->not->toContain((string) $otherApplication->id)
            ->and($this->application->fresh()->status)->toStartWith('running');
    });

    test('Sentinel push looks up foreign application owners with one query for all containers', function () {
        $deleted = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $deleted->delete();

        $containers = collect(range(1, 5))->map(fn (int $i) => [
            'name' => "foreign-{$i}",
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationUuid' => "foreign-app-{$i}",
                'coolify.pullRequestId' => '0',
                'com.docker.compose.service' => "foreign-app-{$i}",
            ],
        ])->push([
            'name' => $deleted->uuid.'-pr-3',
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationUuid' => $deleted->uuid,
                'coolify.pullRequestId' => '3',
                'com.docker.compose.service' => $deleted->uuid.'-pr-3',
            ],
        ])->all();

        $job = new PushServerUpdateJob($this->server, ['containers' => $containers]);

        DB::enableQueryLog();
        $job->handle();
        $ownerLookups = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'from "applications"') && preg_match('/"uuid" (=|in) /', $query['query']) === 1)
            ->count();
        DB::disableQueryLog();

        $previewLabels = end($containers)['labels'];
        expect($ownerLookups)->toBe(1)
            // A trashed owner still resolves, with and without the preloaded ids.
            ->and(resolveContainerApplicationId(collect(), $previewLabels))->toBe($deleted->id)
            ->and(resolveContainerApplicationId(collect(), $previewLabels, containerApplicationIdsByUuid(collect(), [$previewLabels])))->toBe($deleted->id)
            ->and(containerApplicationIdsByUuid(collect([$this->application]), [['coolify.applicationUuid' => $this->application->uuid]]))->toBe([]);
    });

    test('Sentinel push matches a new application container by its UUID label', function () {
        $job = new PushServerUpdateJob($this->server, ['containers' => [[
            'name' => $this->application->uuid,
            'state' => 'running',
            'health_status' => 'healthy',
            'labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationUuid' => $this->application->uuid,
                'coolify.pullRequestId' => '0',
                'com.docker.compose.service' => $this->application->uuid,
            ],
        ]]]);
        $job->handle();

        expect($job->foundApplicationIds->all())->toContain((string) $this->application->id)
            ->and($this->application->fresh()->status)->toStartWith('running');
    });

    test('Sentinel push and status check still match containers from before mid-2024 by the UUID in their name', function () {
        $labels = [
            'coolify.managed' => 'true',
            'coolify.applicationId' => (string) $this->application->id,
            'coolify.pullRequestId' => '0',
            // Before July 2024 the compose project was the deployment directory, not the application UUID.
            'com.docker.compose.project' => 'lwk8ssk0ck4co4w0skkowwss',
            'com.docker.compose.service' => $this->application->uuid,
        ];

        $job = new PushServerUpdateJob($this->server, ['containers' => [[
            'name' => $this->application->uuid, 'state' => 'running', 'health_status' => 'healthy', 'labels' => $labels,
        ]]]);
        $job->handle();
        expect($this->application->fresh()->status)->toStartWith('running');

        $this->application->update(['status' => 'exited']);
        $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        GetContainersStatus::run($this->server->fresh(), collect([[
            'Name' => '/'.$this->application->uuid,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => $labels],
        ]]), collect());
        expect($this->application->fresh()->status)->toStartWith('running');
    });

    test('Sentinel push and status check match a 4.3 compose container deployed with a custom start command', function () {
        $containerName = 'public-api-'.$this->application->uuid.'-104512123456';
        $labels = [
            'coolify.managed' => 'true',
            'coolify.applicationId' => (string) $this->application->id,
            'coolify.pullRequestId' => '0',
            'coolify.name' => $containerName,
            // Without --project-name, the compose project is the deployment directory.
            'com.docker.compose.project' => 'r8wkc0gk0o4ws8gs4s8wwgs4',
            'com.docker.compose.service' => 'public-api',
        ];

        $job = new PushServerUpdateJob($this->server, ['containers' => [[
            'name' => $containerName, 'state' => 'running', 'health_status' => 'healthy', 'labels' => $labels,
        ]]]);
        $job->handle();
        expect($this->application->fresh()->status)->toStartWith('running');

        $this->application->update(['status' => 'exited']);
        $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        GetContainersStatus::run($this->server->fresh(), collect([[
            'Name' => '/'.$containerName,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => $labels],
        ]]), collect());
        expect($this->application->fresh()->status)->toStartWith('running');
    });

    test('container status check matches an older application container by compose project', function () {
        $container = [
            'Name' => '/'.$this->application->uuid,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationId' => '999999',
                'coolify.pullRequestId' => '0',
                'com.docker.compose.project' => $this->application->uuid,
                'com.docker.compose.project.config_files' => '/artifacts/x/docker-compose.yaml',
                'com.docker.compose.service' => $this->application->uuid,
            ]],
        ];

        $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        GetContainersStatus::run($this->server->fresh(), collect([$container]), collect());

        expect($this->application->fresh()->status)->toStartWith('running');
    });

    test('container status check looks up foreign preview owners with one query for all containers', function () {
        $deleted = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $preview = ApplicationPreview::forceCreate([
            'application_id' => $deleted->id,
            'pull_request_id' => 3,
            'pull_request_html_url' => 'https://example.com/pr/3',
            'status' => 'exited',
        ]);
        $deleted->delete();

        $containers = collect(range(1, 5))->map(fn (int $i) => [
            'Name' => "/foreign-app-{$i}-pr-{$i}",
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationUuid' => "foreign-app-{$i}",
                'coolify.pullRequestId' => (string) $i,
                'com.docker.compose.service' => "foreign-app-{$i}-pr-{$i}",
            ]],
        ])->push([
            'Name' => '/'.$deleted->uuid.'-pr-3',
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationUuid' => $deleted->uuid,
                'coolify.pullRequestId' => '3',
                'com.docker.compose.service' => $deleted->uuid.'-pr-3',
            ]],
        ]);

        $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        $server = $this->server->fresh();

        DB::enableQueryLog();
        GetContainersStatus::run($server, $containers, collect());
        $ownerLookups = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'from "applications"') && preg_match('/"uuid" (=|in) /', $query['query']) === 1)
            ->count();
        DB::disableQueryLog();

        expect($ownerLookups)->toBe(1)
            // The preview of a trashed owner still resolves.
            ->and($preview->fresh()->status)->toBe('running:healthy');
    });
});

describe('orphaned preview cleanup', function () {
    test('keeps a transferred preview container whose numeric id belongs to nothing here', function () {
        ApplicationPreview::forceCreate([
            'application_id' => $this->application->id,
            'pull_request_id' => 7,
            'pull_request_html_url' => 'https://example.com/pr/7',
        ]);
        $job = new CleanupOrphanedPreviewContainersJob;
        $isOrphaned = fn (array $container) => (fn () => $this->isOrphanedContainer($container))->call($job);

        expect($isOrphaned(['Labels' => "coolify.applicationId=999999,coolify.pullRequestId=7,com.docker.compose.project={$this->application->uuid}"]))->toBeFalse()
            ->and($isOrphaned(['Labels' => "coolify.applicationUuid={$this->application->uuid},coolify.pullRequestId=7"]))->toBeFalse()
            ->and($isOrphaned(['Labels' => "coolify.applicationUuid={$this->application->uuid},coolify.pullRequestId=8"]))->toBeTrue()
            // Another instance's preview container with the same numeric id: not ours, so never removed.
            ->and($isOrphaned(['Labels' => "coolify.applicationId={$this->application->id},coolify.pullRequestId=8,com.docker.compose.project=other-instance-uuid"]))->toBeFalse()
            ->and($isOrphaned(['Labels' => 'coolify.applicationId=999999,coolify.pullRequestId=7']))->toBeFalse();
    });
});
