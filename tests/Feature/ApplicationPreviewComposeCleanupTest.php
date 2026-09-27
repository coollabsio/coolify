<?php

/**
 * Feature tests for the Docker cleanup that runs when a compose based preview deployment
 * is deleted. The parsed compose file also contains resources Coolify does not own:
 * the volume and network names written by the user, external volumes and the shared
 * Coolify network. Removing those would destroy data and connectivity of other resources.
 */

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'deploy',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - 'app-data:/srv/data'
      - 'rclone-http:/srv/remote'
    networks:
      - backend
volumes:
  app-data:
  rclone-http:
    external: true
networks:
  backend:
YAML,
    ]);
    $this->application->settings()->update(['connect_to_docker_network' => true]);

    $this->preview = ApplicationPreview::create([
        'uuid' => 'preview-compose-cleanup-test',
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
    ]);
});

it('removes only the volumes generated for the preview', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $uuid = $this->application->uuid;

    $this->preview->forceDelete();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker volume rm -f '{$uuid}_app-data-pr-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker volume rm -f 'app-data'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker volume rm -f 'rclone-http'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker volume rm -f '{$uuid}_app-data'"));
});

it('removes only the network created for the preview', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $uuid = $this->application->uuid;

    $this->preview->forceDelete();

    Process::assertRan(fn ($process) => str_contains($process->command, "docker network rm '{$uuid}-42'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker network rm 'backend'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker network rm 'coolify'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker network disconnect 'coolify' coolify-proxy"));
});
