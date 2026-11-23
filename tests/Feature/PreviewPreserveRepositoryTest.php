<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Records the deployment commands instead of running them.
 */
class PreviewPreserveRepositoryDeploymentJob extends ApplicationDeploymentJob
{
    /** @var list<string> */
    public array $commands = [];

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $this->commands[] = $command['command'] ?? $command[0];
        }
    }
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_location' => '/docker-compose.yaml',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - './scripts:/scripts'
      - '/srv/shared:/shared'
YAML,
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->preview = ApplicationPreview::create([
        'uuid' => 'preview-preserve-repository-test',
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/7',
    ]);
    $this->mainDirectory = application_configuration_dir().'/'.$this->application->uuid;
    $this->previewDirectory = $this->mainDirectory.'-pr-7';
});

function preservePreviewRepository(bool $enabled): void
{
    test()->application->settings()->update(['is_preserve_repository_enabled' => $enabled]);
    test()->application->refresh();
}

function previewPreserveDeploymentJob(int $pullRequestId): PreviewPreserveRepositoryDeploymentJob
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => 'deployment-'.$pullRequestId,
        'status' => 'in_progress',
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => $pullRequestId,
    ]);

    return new PreviewPreserveRepositoryDeploymentJob($deployment->id);
}

/**
 * The host paths of the `web` service volumes in a parsed Compose file.
 *
 * @return list<string>
 */
function previewPreserveBindSources(int $pullRequestId): array
{
    $compose = test()->application->fresh()->parse(pull_request_id: $pullRequestId, preview_id: $pullRequestId ? test()->preview->id : null)->toArray();
    $service = $pullRequestId ? addPreviewDeploymentSuffix('web', $pullRequestId) : 'web';

    return collect($compose['services'][$service]['volumes'])
        ->map(fn (string $volume) => str($volume)->beforeLast(':')->value())
        ->all();
}

describe('the deployment configuration directory', function () {
    it('is a separate directory for a preview with Preserve Repository enabled', function () {
        preservePreviewRepository(true);

        $job = previewPreserveDeploymentJob(7);

        expect((new ReflectionProperty(ApplicationDeploymentJob::class, 'configuration_dir'))->getValue($job))->toBe($this->previewDirectory);
    });

    it('does not change for the main deployment or without Preserve Repository', function (bool $preserve, int $pullRequestId) {
        preservePreviewRepository($preserve);

        $job = previewPreserveDeploymentJob($pullRequestId);

        expect((new ReflectionProperty(ApplicationDeploymentJob::class, 'configuration_dir'))->getValue($job))->toBe($this->mainDirectory);
    })->with([
        'main deployment, preserve on' => [true, 0],
        'preview, preserve off' => [false, 7],
    ]);

    it('copies the repository and starts the preview Compose project in the preview directory', function () {
        preservePreviewRepository(true);
        $job = previewPreserveDeploymentJob(7);
        (new ReflectionProperty(ApplicationDeploymentJob::class, 'docker_compose_location'))->setValue($job, '/docker-compose-pr-7.yaml');

        (new ReflectionMethod(ApplicationDeploymentJob::class, 'start_docker_compose_services'))->invoke($job);

        $commands = implode("\n", $job->commands);
        expect($commands)
            ->toContain("docker cp deployment-7:/artifacts/deployment-7/. {$this->previewDirectory}")
            ->toContain("--env-file {$this->previewDirectory}/.env")
            ->toContain("--project-directory {$this->previewDirectory} -f {$this->previewDirectory}/docker-compose-pr-7.yaml up -d")
            ->not->toContain("{$this->mainDirectory} ")
            ->not->toContain("{$this->mainDirectory}/");
    });

    it('starts the main Compose project in the main directory', function () {
        preservePreviewRepository(true);
        $job = previewPreserveDeploymentJob(0);

        (new ReflectionMethod(ApplicationDeploymentJob::class, 'start_docker_compose_services'))->invoke($job);

        expect(implode("\n", $job->commands))
            ->toContain("docker cp deployment-0:/artifacts/deployment-0/. {$this->mainDirectory}")
            ->toContain("--project-directory {$this->mainDirectory} -f {$this->mainDirectory}/docker-compose.yaml up -d");
    });
});

describe('preview bind sources', function () {
    it('resolves relative sources in the preview directory with Preserve Repository enabled', function () {
        preservePreviewRepository(true);

        expect(previewPreserveBindSources(7))->toBe([
            "{$this->previewDirectory}/scripts",
            '/srv/shared-pr-7',
        ]);
    });

    it('keeps a relative source of the main directory when the preview suffix is disabled', function () {
        preservePreviewRepository(true);
        LocalFileVolume::create([
            'fs_path' => "{$this->mainDirectory}/scripts",
            'mount_path' => '/scripts',
            'is_directory' => true,
            'is_preview_suffix_enabled' => false,
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
        ]);

        expect(previewPreserveBindSources(7)[0])->toBe("{$this->mainDirectory}/scripts");
    });

    it('does not change the sources of the main deployment or of a preview without Preserve Repository', function (bool $preserve, int $pullRequestId, string $expected) {
        preservePreviewRepository($preserve);

        expect(previewPreserveBindSources($pullRequestId)[0])
            ->toBe(str_replace('{dir}', $this->mainDirectory, $expected));
    })->with([
        'main deployment, preserve on' => [true, 0, '{dir}/scripts'],
        'preview, preserve off' => [false, 7, '{dir}/scripts-pr-7'],
    ]);
});

describe('cleanup', function () {
    it('deletes the preview directory when the preview is deleted', function () {
        $this->preview->forceDelete();

        Process::assertRan(fn ($process) => str_contains($process->command, 'rm -rf '.escapeshellarg($this->previewDirectory)));
        Process::assertNotRan(fn ($process) => str_contains($process->command, 'rm -rf '.escapeshellarg($this->mainDirectory).' '));
    });

    it('deletes the preview directories when the application configuration is deleted', function () {
        $this->preview->delete();

        $this->application->deleteConfigurations();

        Process::assertRan(fn ($process) => str_contains($process->command, "rm -rf {$this->mainDirectory} {$this->previewDirectory}"));
    });
});
