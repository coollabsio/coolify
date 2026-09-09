<?php

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\BaseModel;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandalonePostgresql;
use App\Notifications\Application\RestartLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);
});

function applicationWithRestartState(array $attributes = []): Application
{
    $application = new Application;
    $application->forceFill(array_merge([
        'status' => 'exited:unhealthy',
        'container_present' => true,
        'restart_count' => 2,
        'max_restart_count' => 2,
        'restart_limit_reached' => true,
        'last_restart_type' => 'crash',
        'last_restart_at' => now(),
    ], $attributes));

    return $application;
}

it('detects applications stopped after reaching the crash restart limit', function () {
    expect(applicationWithRestartState()->stoppedAfterRestartLimit())->toBeTrue()
        ->and(applicationWithRestartState(['status' => 'running:unhealthy'])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['restart_limit_reached' => false])->stoppedAfterRestartLimit())->toBeFalse();
});

it('keeps the restart limit state after Docker resets its counter', function () {
    expect(applicationWithRestartState([
        'restart_count' => 0,
        'last_restart_type' => null,
        'last_restart_at' => null,
    ])->stoppedAfterRestartLimit())->toBeTrue();
});

it('preserves exited application state when the container snapshot is empty', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->setRelation('additional_servers', collect());
    $application->forceFill([
        'id' => 1,
        'status' => 'exited:unhealthy',
        'container_present' => true,
        'restart_limit_reached' => true,
    ]);
    $application->shouldNotReceive('update');

    $services = Mockery::mock();
    $services->shouldReceive('get')->once()->andReturn(collect());

    $server = Mockery::mock(Server::class, function (MockInterface $mock) use ($application, $services) {
        $mock->shouldReceive('isFunctional')->once()->andReturnTrue();
        $mock->shouldReceive('applications')->once()->andReturn(collect([$application]));
        $mock->shouldReceive('databases')->once()->andReturn(collect());
        $mock->shouldReceive('services')->once()->andReturn($services);
        $mock->shouldReceive('previews')->once()->andReturn(collect());
    })->makePartial();
    $server->setRelation('team', (object) ['id' => 1]);

    GetContainersStatus::run($server, collect(), collect());

    expect($application->container_present)->toBeTrue()
        ->and($application->restart_limit_reached)->toBeTrue();
});

it('does not infer the restart limit from an exited existing container', function () {
    expect(applicationWithRestartState([
        'container_present' => true,
        'restart_limit_reached' => false,
    ])->stoppedAfterRestartLimit())->toBeFalse();
});

it('shows a stopped after restart limit warning in the status badge', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState(),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->toContain('Restart limit reached')
        ->not->toContain('Stopped after reaching restart limit (2/2).')
        ->and($html)->toContain('Container has crashed and Coolify stopped it after 2 restart attempts.');
});

it('does not show the restart limit warning for a normal manual stop', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState([
            'restart_count' => 0,
            'last_restart_type' => null,
            'restart_limit_reached' => false,
        ]),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->not->toContain('Restart limit reached');
});

it('keeps restart tracking configurable when stopping an application', function () {
    $method = new ReflectionMethod(StopApplication::class, 'handle');
    $resetRestartCount = collect($method->getParameters())->firstWhere('name', 'resetRestartCount');

    expect($resetRestartCount)->not->toBeNull()
        ->and($resetRestartCount->getDefaultValue())->toBeTrue();
});

it('can stop an application without removing its containers', function () {
    $method = new ReflectionMethod(StopApplication::class, 'handle');
    $removeContainers = collect($method->getParameters())->firstWhere('name', 'removeContainers');
    $action = file_get_contents(app_path('Actions/Application/StopApplication.php'));

    expect($removeContainers)->not->toBeNull()
        ->and($removeContainers->getDefaultValue())->toBeTrue()
        ->and($action)->not->toContain('docker update --restart=no')
        ->and($action)->toContain('if ($removeContainers)');
});

it('preserves containers and skips cleanup when the restart limit is reached', function () {
    $statusAction = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelJob = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    expect($statusAction)->toContain('dockerCleanup: false')
        ->and($statusAction)->toContain('resetRestartCount: false')
        ->and($statusAction)->toContain('removeContainers: false')
        ->and($statusAction)->toContain("['restart_limit_reached' => true]")
        ->and($sentinelJob)->toContain("['restart_limit_reached' => true]");
});

it('atomically claims the restart limit transition before stopping and notifying', function () {
    $statusAction = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelJob = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    foreach ([$statusAction, $sentinelJob] as $detector) {
        expect($detector)
            ->toContain("->where('restart_limit_reached', false)")
            ->toContain("->update(['restart_limit_reached' => true]) === 1");
    }
});

it('clears the explicit restart limit state only after a successful main deployment', function () {
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'handleSuccessfulDeployment');
    $source = file($method->getFileName());
    $deploymentJob = implode(array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect(substr_count($deploymentJob, "'restart_limit_reached'] = false"))->toBe(1)
        ->and($deploymentJob)->toContain("if (\$this->pull_request_id === 0) {\n            \$restartState['restart_limit_reached'] = false;\n        }")
        ->and($deploymentJob)->toContain('$this->application->update($restartState);')
        ->and(substr_count($deploymentJob, '$this->application->update('))->toBe(1);
});

it('preserves restart-limit applications only while their exited container exists', function () {
    $statusAction = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelJob = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    expect($statusAction)->toContain("'container_present' => false")
        ->and($statusAction)->toContain("'restart_limit_reached' => false")
        ->and($sentinelJob)->toContain('if ($application->stoppedAfterRestartLimit() && $containerStatuses->every(');
});

it('builds restart limit notification urls from the instance base url', function () {
    $application = new Application;
    $application->forceFill([
        'name' => 'crashy-app',
        'uuid' => 'application-uuid',
        'restart_count' => 2,
        'max_restart_count' => 2,
    ]);
    $application->setRelation('environment', (object) [
        'uuid' => 'environment-uuid',
        'name' => 'production',
        'project' => (object) ['uuid' => 'project-uuid'],
    ]);

    $notification = new RestartLimitReached($application);

    expect($notification->resource_url)->toBe('https://coolify.test/project/project-uuid/environment/environment-uuid/application/application-uuid');
});

it('links preview, service resource and database restart limit notifications to their pages', function () {
    $environment = (object) ['uuid' => 'environment-uuid', 'name' => 'production', 'project' => (object) ['uuid' => 'project-uuid']];

    $application = new Application;
    $application->forceFill(['name' => 'app', 'uuid' => 'application-uuid']);
    $application->setRelation('environment', $environment);
    $preview = new ApplicationPreview;
    $preview->forceFill(['uuid' => 'preview-uuid', 'pull_request_id' => 42, 'restart_count' => 2, 'max_restart_count' => 2]);
    $preview->setRelation('application', $application);

    $service = new Service;
    $service->forceFill(['uuid' => 'service-uuid']);
    $service->setRelation('environment', $environment);
    $serviceApplication = new ServiceApplication;
    $serviceApplication->forceFill(['name' => 'database', 'uuid' => 'service-application-uuid', 'restart_count' => 2, 'max_restart_count' => 2]);
    $serviceApplication->setRelation('service', $service);

    $database = new StandalonePostgresql;
    $database->forceFill(['name' => 'postgres', 'uuid' => 'database-uuid', 'restart_count' => 2, 'max_restart_count' => 2]);
    $database->setRelation('environment', $environment);

    expect((new RestartLimitReached($preview))->resource_url)->toBe('https://coolify.test/project/project-uuid/environment/environment-uuid/application/application-uuid')
        ->and((new RestartLimitReached($serviceApplication))->resource_url)->toBe('https://coolify.test/project/project-uuid/environment/environment-uuid/service/service-uuid')
        ->and((new RestartLimitReached($database))->resource_url)->toBe('https://coolify.test/project/project-uuid/environment/environment-uuid/database/database-uuid');
});

it('uses the resolved environment project name in Slack restart limit notifications', function () {
    $environment = (object) [
        'uuid' => 'environment-uuid',
        'name' => 'production',
        'project' => (object) [
            'uuid' => 'project-uuid',
            'name' => 'Coolify',
        ],
    ];

    $application = new class extends Application
    {
        public function link(): string
        {
            return 'https://coolify.test/application';
        }
    };
    $application->forceFill(['name' => 'app']);
    $application->setRelation('environment', $environment);

    $preview = new ApplicationPreview;
    $preview->forceFill([
        'uuid' => 'preview-uuid',
        'pull_request_id' => 42,
        'restart_count' => 2,
        'max_restart_count' => 2,
    ]);
    $preview->setRelation('application', $application);

    $serviceResource = new class extends BaseModel {};
    $serviceResource->forceFill([
        'name' => 'database',
        'uuid' => 'service-resource-uuid',
        'restart_count' => 2,
        'max_restart_count' => 2,
    ]);
    $serviceResource->setRelation('service', new class($environment)
    {
        public function __construct(public object $environment) {}

        public function link(): string
        {
            return 'https://coolify.test/service';
        }
    });

    expect((new RestartLimitReached($preview))->toSlack()->description)
        ->toContain('*Project:* Coolify')
        ->and((new RestartLimitReached($serviceResource))->toSlack()->description)
        ->toContain('*Project:* Coolify');
});
