<?php

namespace App\Livewire\Project\Service {
    use App\Models\Server;

    class ContainerInfoTestSpy
    {
        public static array $commands = [];

        public static ?string $inspectResponse = null;

        public static function reset(): void
        {
            self::$commands = [];
            self::$inspectResponse = null;
        }
    }

    function instant_remote_process(array $command, Server $server, bool $throwError = true): ?string
    {
        ContainerInfoTestSpy::$commands[] = $command[0] ?? null;

        return ContainerInfoTestSpy::$inspectResponse;
    }
}

namespace App\Livewire\Project\Application {
    use App\Livewire\Project\Service\ContainerInfoTestSpy;
    use App\Models\Server;

    function instant_remote_process(array $command, Server $server, bool $throwError = true): ?string
    {
        ContainerInfoTestSpy::$commands[] = $command[0] ?? null;

        return ContainerInfoTestSpy::$inspectResponse;
    }
}

namespace App\Models {
    function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase|Application $oneService, bool $isInit = false): void {}
}

namespace {

    use App\Livewire\Project\Service\ContainerInfoTestSpy;
    use App\Models\Application;
    use App\Models\Environment;
    use App\Models\InstanceSettings;
    use App\Models\PrivateKey;
    use App\Models\Project;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\StandaloneDocker;
    use App\Models\Team;
    use App\Models\User;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Str;

    uses(RefreshDatabase::class);

    beforeEach(function () {
        ContainerInfoTestSpy::reset();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user->id, ['role' => 'owner']);

        $this->actingAs($this->user);
        $this->withoutVite();
        session(['currentTeam' => $this->team]);

        InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

        $privateKey = PrivateKey::create([
            'name' => 'Test Key',
            'private_key' => generateSSHKey()['private'],
            'team_id' => $this->team->id,
        ]);

        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $privateKey->id,
            'ip' => 'coolify-testing-host',
        ]);

        $this->destination = StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'network' => 'coolify-'.Str::random(8),
        ]);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $this->environment = Environment::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'status' => 'running',
        ]);

        $this->service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);
    });

    it('renders the read-only container info card with copy actions only for stable identifiers and IPs', function () {
        $view = view('components.container-info', [
            'containerInfo' => [
                'id' => 'c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f',
                'name' => 'web-service-uuid',
                'image' => 'ghcr.io/example/app:1.2.3',
                'created_at' => '2026-04-24T12:34:56.123456789Z',
                'started_at' => '2026-04-24T12:35:10.987654321Z',
                'ipv4_addresses' => ['172.18.0.5'],
                'ipv6_addresses' => ['fd00::5'],
            ],
        ])->render();

        expect($view)
            ->toContain('Container Info')
            ->toContain('Container ID')
            ->toContain('Container Name')
            ->toContain('Image')
            ->toContain('Created At')
            ->toContain('Started At')
            ->toContain('IPv4')
            ->toContain('IPv6')
            ->toContain('web-service-uuid')
            ->toContain('ghcr.io/example/app:1.2.3')
            ->toContain('Copy container ID')
            ->toContain('Copy container name')
            ->toContain('Copy IPv4 address')
            ->toContain('Copy IPv6 address')
            ->not->toContain('Copy image')
            ->not->toContain('Copy created at')
            ->not->toContain('Copy started at');
    });

    it('loads container info for service application routes using the full service identity tuple', function () {
        $serviceApplication = $this->service->applications()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'app',
            'image' => 'ghcr.io/example/app:1.2.3',
        ]);

        ContainerInfoTestSpy::$inspectResponse = json_encode([[
            'Id' => 'container-id',
            'Name' => '/service-application',
            'Config' => ['Image' => 'ghcr.io/example/app:1.2.3'],
            'Created' => '2026-04-24T12:34:56.123456789Z',
            'State' => ['StartedAt' => '2026-04-24T12:35:10.987654321Z'],
            'NetworkSettings' => ['Networks' => ['coolify' => ['IPAddress' => '172.18.0.5', 'GlobalIPv6Address' => '']]],
        ]]);

        $response = $this->get(route('project.service.index', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
            'stack_service_uuid' => $serviceApplication->uuid,
        ]));

        $response->assertOk()->assertSee('Container Info')->assertSee('service-application');

        expect(ContainerInfoTestSpy::$commands)
            ->toHaveCount(1)
            ->and(ContainerInfoTestSpy::$commands[0])
            ->toContain("coolify.serviceId={$this->service->id}")
            ->toContain('coolify.service.subType=application')
            ->toContain("coolify.service.subId={$serviceApplication->id}");
    });

    it('loads container info for service database routes using the full service identity tuple', function () {
        $serviceDatabase = $this->service->databases()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'db',
            'image' => 'postgres:16',
        ]);

        ContainerInfoTestSpy::$inspectResponse = json_encode([[
            'Id' => 'db-container-id',
            'Name' => '/service-database',
            'Config' => ['Image' => 'postgres:16'],
            'Created' => '2026-04-24T12:34:56.123456789Z',
            'State' => ['StartedAt' => '2026-04-24T12:35:10.987654321Z'],
            'NetworkSettings' => ['Networks' => ['coolify' => ['IPAddress' => '172.18.0.6', 'GlobalIPv6Address' => '']]],
        ]]);

        $response = $this->get(route('project.service.index', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
            'stack_service_uuid' => $serviceDatabase->uuid,
        ]));

        $response->assertOk()->assertSee('Container Info')->assertSee('service-database');

        expect(ContainerInfoTestSpy::$commands)
            ->toHaveCount(1)
            ->and(ContainerInfoTestSpy::$commands[0])
            ->toContain("coolify.serviceId={$this->service->id}")
            ->toContain('coolify.service.subType=database')
            ->toContain("coolify.service.subId={$serviceDatabase->id}");
    });

    it('loads container info for standalone application general routes using the application label selector', function () {
        ContainerInfoTestSpy::$inspectResponse = json_encode([[
            'Id' => 'application-container-id',
            'Name' => '/standalone-application',
            'Config' => ['Image' => 'ghcr.io/example/app:9.9.9'],
            'Created' => '2026-04-24T12:34:56.123456789Z',
            'State' => ['StartedAt' => '2026-04-24T12:35:10.987654321Z'],
            'NetworkSettings' => ['Networks' => ['coolify' => ['IPAddress' => '172.18.0.7', 'GlobalIPv6Address' => '']]],
        ]]);

        $response = $this->get(route('project.application.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ]));

        $response->assertOk()
            ->assertSee('Container Info')
            ->assertSee('standalone-application')
            ->assertSee('Copy container ID')
            ->assertSee('Copy IPv4 address');

        expect(ContainerInfoTestSpy::$commands)
            ->toHaveCount(1)
            ->and(ContainerInfoTestSpy::$commands[0])
            ->toContain("coolify.applicationId={$this->application->id}")
            ->toContain('coolify.pullRequestId=0');
    });

    it('does not inspect standalone application containers on advanced routes that do not render the card', function () {
        $response = $this->get(route('project.application.advanced', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ]));

        $response->assertOk()->assertDontSee('Container Info');

        expect(ContainerInfoTestSpy::$commands)->toBeEmpty();
    });

    it('skips the remote container inspect when the advanced route does not render the container card', function () {
        $serviceApplication = $this->service->applications()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'app',
            'image' => 'ghcr.io/example/app:1.2.3',
        ]);

        $response = $this->get(route('project.service.index.advanced', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
            'stack_service_uuid' => $serviceApplication->uuid,
        ]));

        $response->assertOk()->assertDontSee('Container Info');

        expect(ContainerInfoTestSpy::$commands)->toBeEmpty();
    });

}
