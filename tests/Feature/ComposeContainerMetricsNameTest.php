<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
  queue_worker:
    image: redis:alpine
YAML,
    ]);
});

function composeServiceLabels(mixed $compose, string $service): array
{
    return collect(data_get($compose, "services.{$service}.labels"))->values()->all();
}

it('keeps the compose container metrics name stable across deployments', function () {
    $this->travelTo(now()->setDateTime(2026, 1, 1, 12, 0, 0));
    $firstDeployment = applicationParser($this->application->fresh());

    $this->travel(1)->hours();
    $secondDeployment = applicationParser($this->application->fresh());

    expect(data_get($firstDeployment, 'services.web.container_name'))
        ->not->toBe(data_get($secondDeployment, 'services.web.container_name'))
        ->and(composeServiceLabels($firstDeployment, 'web'))->toContain("coolify.name=web-{$this->application->uuid}")
        ->and(composeServiceLabels($secondDeployment, 'web'))->toContain("coolify.name=web-{$this->application->uuid}")
        ->and(composeServiceLabels($secondDeployment, 'queue_worker'))->toContain("coolify.name=queue-worker-{$this->application->uuid}");
});

it('suffixes the compose container metrics name of preview deployments', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/42',
    ]);

    $compose = applicationParser($this->application->fresh(), 42, $preview->id);

    expect(composeServiceLabels($compose, 'web-pr-42'))
        ->toContain("coolify.name=web-{$this->application->uuid}-pr-42");
});

it('keeps the compose container metrics name stable with the legacy parser', function () {
    $this->application->update(['compose_parsing_version' => '2']);

    $compose = parseDockerComposeFile($this->application->fresh());

    expect(composeServiceLabels($compose, 'web'))
        ->toContain("coolify.name=web-{$this->application->uuid}")
        ->not->toContain('coolify.name='.Str::slug(data_get($compose, 'services.web.container_name')));
});
