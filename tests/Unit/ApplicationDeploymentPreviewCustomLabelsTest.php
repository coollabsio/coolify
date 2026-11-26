<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class TestablePreviewCustomLabelsDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

/**
 * @return array<int, string>
 */
function generatePreviewContainerLabels(array $applicationAttributes): array
{
    $team = Team::create(['name' => 'Preview Labels Team', 'personal_team' => false, 'show_boarding' => false]);
    $project = Project::create(['name' => 'Preview Labels Project', 'team_id' => $team->id]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://app.example.com',
        'ports_exposes' => '3000',
    ], $applicationAttributes));
    $application->settings()->update(['is_container_label_readonly_enabled' => false]);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/7',
        'fqdn' => 'https://pr-7.app.example.com',
    ]);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->status = 'queued';
    $queue->shouldReceive('refresh')->andReturnSelf();

    $job = new TestablePreviewCustomLabelsDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $containerName = "{$application->uuid}-pr-7";

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'destination' => $destination,
        'server' => $server,
        'mainServer' => $server,
        'pull_request_id' => 7,
        'preview' => $preview->fresh(),
        'container_name' => $containerName,
        'production_image_name' => 'example/app:pr-7',
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/preview-app',
        'configuration_dir' => '/data/coolify/applications/test',
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('generate_compose_file')->invoke($job);
    $compose = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));

    return $compose['services'][$containerName]['labels'];
}

it('gives preview containers the custom labels that are not proxy labels', function () {
    $labels = generatePreviewContainerLabels([
        'custom_labels' => base64_encode(implode("\n", [
            'traefik.enable=true',
            'traefik.http.routers.main-app.rule=Host(`app.example.com`)',
            'traefik.http.routers.main-app.middlewares=my-auth',
            'traefik.http.middlewares.my-auth.basicauth.users=user:hash',
            'caddy_0=https://app.example.com',
            'coolify.managed=false',
            'logging=promtail',
            'com.example.team=web',
        ])),
    ]);

    expect($labels)
        ->toContain('logging=promtail')
        ->toContain('com.example.team=web')
        ->toContain('coolify.managed=true')
        ->not->toContain('traefik.http.routers.main-app.rule=Host(`app.example.com`)')
        ->not->toContain('traefik.http.middlewares.my-auth.basicauth.users=user:hash')
        ->not->toContain('caddy_0=https://app.example.com')
        ->and(collect($labels)->filter(fn (string $label) => str_contains($label, 'Host(`pr-7.app.example.com`)')))->not->toBeEmpty();
});

it('protects preview containers with the HTTP basic auth setting of the application', function () {
    $labels = generatePreviewContainerLabels([
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'client',
        'http_basic_auth_password' => 'secret',
        'custom_labels' => base64_encode('logging=promtail'),
    ]);

    $application = Application::query()->latest('id')->firstOrFail();
    $middleware = "http-basic-auth-{$application->uuid}-pr-7";

    expect(collect($labels)->first(fn (string $label) => str_starts_with($label, "traefik.http.middlewares.{$middleware}.basicauth.users=client:")))->not->toBeNull()
        ->and(collect($labels)->filter(fn (string $label) => str_contains($label, '.middlewares=') && str_contains($label, $middleware)))->not->toBeEmpty();
});
