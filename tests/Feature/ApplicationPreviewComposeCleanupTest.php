<?php

use App\Jobs\DeleteResourceJob;
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

/**
 * A Compose file that uses shared networks and volumes next to its own resources.
 */
const PREVIEW_CLEANUP_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - app-data:/data
      - shared-data:/shared
    networks:
      - backend
      - proxy
      - coolify
volumes:
  app-data: {}
  shared-data:
    external: true
    name: shared-data
  main-only:
    name: main-db-data
networks:
  backend: {}
  proxy:
    external: true
    name: shared-proxy
  coolify:
    external: true
YAML;

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'deploy',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => PREVIEW_CLEANUP_COMPOSE,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->application->settings->update(['connect_to_docker_network' => true]);
    $this->destinationNetwork = $destination->network;
    $this->uuid = $this->application->uuid;
    $this->previewNetwork = "{$this->uuid}-42";

    $this->preview = ApplicationPreview::create([
        'uuid' => 'preview-compose-cleanup-test',
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
    ]);
});

/**
 * @param  array<int, string>  $commands
 * @param  array<string, mixed>|null  $previewNetworkContainers  null when the network does not exist
 */
function fakePreviewCleanupServer(array &$commands, string $previewNetwork, ?array $previewNetworkContainers = [], string $dockerPs = ''): void
{
    Process::fake(function ($process) use (&$commands, $previewNetwork, $previewNetworkContainers, $dockerPs) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands[] = $command;

        if (str_contains($command, 'docker network inspect') && str_contains($command, "'{$previewNetwork}'")) {
            if ($previewNetworkContainers === null) {
                return Process::result(errorOutput: "Error response from daemon: network {$previewNetwork} not found", exitCode: 1);
            }

            return Process::result(output: json_encode((object) $previewNetworkContainers));
        }
        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: $dockerPs);
        }

        return Process::result(output: '');
    });
}

/**
 * @param  array<int, string>  $commands
 * @return array<int, string>
 */
function dockerResourceCommands(array $commands): array
{
    return array_values(array_filter(
        $commands,
        fn (string $command): bool => str_contains($command, 'docker volume') || str_contains($command, 'docker network'),
    ));
}

it('removes only the network and volumes that Coolify generated for the preview', function () {
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, ['abc' => ['Name' => 'coolify-proxy']]);

    $this->preview->forceDelete();

    $resourceCommands = implode("\n", dockerResourceCommands($commands));

    expect($resourceCommands)
        ->toContain("sudo docker volume rm -f '{$this->uuid}_app-data-pr-42'")
        ->not->toContain('shared-data-pr-42')
        ->toContain("sudo docker network disconnect '{$this->previewNetwork}' coolify-proxy")
        ->toContain("sudo docker network rm '{$this->previewNetwork}'")
        ->not->toContain("'app-data'")
        ->not->toContain("'shared-data'")
        ->not->toContain("'main-only'")
        ->not->toContain("'main-db-data'")
        ->not->toContain("'backend'")
        ->not->toContain("'proxy'")
        ->not->toContain("'shared-proxy'")
        ->not->toContain("'coolify'")
        ->not->toContain("'{$this->destinationNetwork}'");

    expect(ApplicationPreview::withTrashed()->find($this->preview->id))->toBeNull();
});

it('keeps the preview network when another container still uses it', function () {
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, [
        'abc' => ['Name' => 'coolify-proxy'],
        'def' => ['Name' => 'other-app-container'],
    ]);

    $this->preview->forceDelete();

    $resourceCommands = implode("\n", dockerResourceCommands($commands));

    expect($resourceCommands)
        ->toContain("docker network inspect --format '{{json .Containers}}' '{$this->previewNetwork}'")
        ->not->toContain('docker network disconnect')
        ->not->toContain('docker network rm');
});

it('does not run network commands when the preview network does not exist', function () {
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, null);

    $this->preview->forceDelete();

    $resourceCommands = implode("\n", dockerResourceCommands($commands));

    expect($resourceCommands)
        ->not->toContain('docker network disconnect')
        ->not->toContain('docker network rm')
        ->toContain("docker volume rm -f '{$this->uuid}_app-data-pr-42'");
});

it('keeps the destination network when it has the generated preview name pattern', function () {
    StandaloneDocker::query()->whereKey($this->application->destination_id)->update(['network' => $this->previewNetwork]);
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, []);

    $this->preview->forceDelete();

    expect(implode("\n", dockerResourceCommands($commands)))
        ->not->toContain('docker network disconnect')
        ->not->toContain('docker network rm');
});

it('uses remove commands with quoted values that the non-root sudo parser keeps intact', function () {
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, []);

    $this->preview->forceDelete();

    foreach (dockerResourceCommands($commands) as $command) {
        $remoteCommand = str($command)->after('sudo ')->before("\n")->value();
        expect($command)->toContain('sudo docker ')
            ->and($command)->not->toContain('sudo sudo')
            ->and($command)->not->toContain('| sudo')
            ->and($remoteCommand)->not->toContain('$(');
    }
});

it('removes the preview containers, network, and volumes through the delete job and keeps shared resources', function () {
    $previewContainer = json_encode([
        'Names' => "web-{$this->uuid}-pr-42",
        'Labels' => "coolify.applicationId={$this->application->id},coolify.pullRequestId=42",
        'State' => 'running',
    ]);
    $mainContainer = json_encode([
        'Names' => "web-{$this->uuid}",
        'Labels' => "coolify.applicationId={$this->application->id},coolify.pullRequestId=0",
        'State' => 'running',
    ]);
    $commands = [];
    fakePreviewCleanupServer($commands, $this->previewNetwork, [], $previewContainer."\n".$mainContainer);

    (new DeleteResourceJob($this->preview))->handle();

    $all = implode("\n", $commands);
    $containerRemoval = collect($commands)->search(fn (string $command): bool => str_contains($command, "docker rm -f 'web-{$this->uuid}-pr-42'"));
    $networkRemoval = collect($commands)->search(fn (string $command): bool => str_contains($command, "docker network rm '{$this->previewNetwork}'"));

    expect($containerRemoval)->not->toBeFalse()
        ->and($networkRemoval)->not->toBeFalse()
        ->and($containerRemoval)->toBeLessThan($networkRemoval)
        ->and($all)->not->toContain("docker rm -f 'web-{$this->uuid}'")
        ->and($all)->toContain("docker volume rm -f '{$this->uuid}_app-data-pr-42'")
        ->and(implode("\n", dockerResourceCommands($commands)))
        ->not->toContain("'coolify'")
        ->not->toContain("'shared-proxy'")
        ->not->toContain("'shared-data'");

    expect(ApplicationPreview::withTrashed()->find($this->preview->id))->toBeNull();
});

it('parses a preview with a bind mount in development mode', function () {
    // In development, bind sources are rewritten to the dev volume; the preview suffix returned a plain string.
    config(['app.env' => 'local']);
    Process::fake();
    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - type: bind\n        source: ./config/app.conf\n        target: /etc/app.conf\n        content: hello\n"]);

    $compose = $this->application->fresh()->parse(pull_request_id: 42);

    expect(json_encode($compose, JSON_UNESCAPED_SLASHES))->toContain('/config/app.conf-pr-42');
});
