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
    test('database and service lookups match the UUID label, the compose project, and the older id label', function () {
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
            ->toContain("label=coolify.databaseId={$database->id}")
            ->toContain("label=coolify.serviceUuid={$service->uuid}")
            ->toContain("label=com.docker.compose.project={$service->uuid}")
            ->toContain("label=coolify.serviceId={$service->id}");
    });

    test('service part containers are matched by UUID label, compose service name, then older id', function () {
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
            // Older container with another compose project: local ids.
            ->and($partOf(['coolify.serviceId' => (string) $service->id, 'coolify.service.subType' => 'application', 'coolify.service.subId' => (string) $part->id, 'com.docker.compose.project' => 'other']))->toBe($part->id);
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

    test('containers from before mid-2024 fall back to their local numeric id', function () {
        $applications = collect([$this->application]);
        $olderContainer = ['coolify.applicationId' => (string) $this->application->id, 'com.docker.compose.project' => 'deployment-uuid'];

        expect(resolveContainerOwner($applications, $olderContainer, 'application')?->id)->toBe($this->application->id)
            // A UUID label is never overridden by the numeric id.
            ->and(resolveContainerOwner($applications, ['coolify.applicationUuid' => 'unknown', 'coolify.applicationId' => (string) $this->application->id], 'application'))->toBeNull()
            ->and(dockerPsByOwnerCommands('application', 'app-uuid', legacyId: 42))->toHaveCount(3)
            ->and(dockerPsByOwnerCommands('application', 'app-uuid', legacyId: 42)[2])->toContain("--filter 'label=coolify.applicationId=42'");
    });

    test('docker ps commands match the UUID label and the compose project of older containers', function () {
        $commands = dockerPsByOwnerCommands('application', 'app-uuid', ['label=coolify.pullRequestId=0']);

        expect($commands)->toHaveCount(2)
            ->and($commands[0])->toContain("--filter 'label=coolify.applicationUuid=app-uuid'")
            ->toContain("--filter 'label=coolify.pullRequestId=0'")
            ->and($commands[1])->toContain("--filter 'label=coolify.applicationId'")
            ->toContain("--filter 'label=com.docker.compose.project=app-uuid'")
            ->toContain("--filter 'label=coolify.pullRequestId=0'");
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

    test('Sentinel push and status check still match containers from before mid-2024 by their local id', function () {
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
            // Containers from before mid-2024: another compose project, so the local id is used, as before.
            ->and($isOrphaned(['Labels' => "coolify.applicationId={$this->application->id},coolify.pullRequestId=7,com.docker.compose.project=deployment-uuid"]))->toBeFalse()
            ->and($isOrphaned(['Labels' => 'coolify.applicationId=999999,coolify.pullRequestId=7']))->toBeTrue();
    });
});
