<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

class ConfigurationSymlinkWriteDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

/**
 * The repository can contain symbolic links. With "Preserve repository", `docker cp` copies them into the
 * resource directory on the server. Coolify replaces such a link with a regular file when it writes its own files.
 * The test runs the remote and helper container commands in a local shell: `docker exec` runs the
 * command directly, and `docker cp` copies like Docker does (it replaces a link at the destination).
 * `sudo` skips the `find ... chown` for the non-root user, because that user does not exist locally.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);

    $this->root = sys_get_temp_dir().'/coolify-symlink-write-'.uniqid();
    $this->workdir = "{$this->root}/artifacts";
    $this->configurationDir = "{$this->root}/applications/app";
    $this->linkTargets = "{$this->root}/link-targets";
    foreach ([$this->workdir, $this->configurationDir, $this->linkTargets] as $directory) {
        File::ensureDirectoryExists($directory);
    }

    $shellPrelude = <<<'SH'
        sudo() { [ "$1" = find ] || "$@"; }
        docker() {
            case "$1" in
                exec) shift; [ "$1" = "-i" ] && shift; shift; "$@" ;;
                cp) cp -a --remove-destination "${2#*:}" "$3" ;;
            esac
        }
        SH;
    Process::fake(function (PendingProcess $process) use ($shellPrelude) {
        $command = $process->command;
        if (preg_match('~<< \\\\(\S+)\n(.*)\n\1$~s', $command, $matches)) {
            $script = $matches[2];
        } elseif (preg_match("~ '((?:[^']|'\\\\'')*)'$~s", $command, $matches)) {
            $script = str_replace("'\\''", "'", $matches[1]);
        } else {
            return Process::result();
        }

        $shell = new SymfonyProcess(['bash', '-c', $shellPrelude."\nset -e\n".$script]);
        $shell->setInput($process->input);
        $shell->run();

        return Process::result(output: $shell->getOutput(), errorOutput: $shell->getErrorOutput(), exitCode: $shell->getExitCode());
    });

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'user' => 'root',
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $this->application->settings()->update([
        'is_preserve_repository_enabled' => true,
        'include_source_commit_in_build' => false,
        'is_env_sorting_enabled' => false,
    ]);
    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'symlink-write-deployment',
        'application_id' => $this->application->id,
        'server_id' => $this->server->id,
        'status' => 'in_progress',
    ]);

    $this->makeJob = function () use ($queue): array {
        $job = new ConfigurationSymlinkWriteDeploymentJob;
        $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
        foreach ([
            'application' => $this->application->fresh(),
            'application_deployment_queue' => $queue,
            'server' => $this->server,
            'mainServer' => $this->server,
            'build_pack' => 'dockerfile',
            'pull_request_id' => 0,
            'commit' => 'HEAD',
            'basedir' => $this->workdir,
            'workdir' => $this->workdir,
            'configuration_dir' => $this->configurationDir,
            'deployment_uuid' => 'symlink-write-deployment',
            'container_name' => 'symlink-write-app',
            'coolify_variables' => null,
            'preserveRepository' => true,
            'use_build_server' => false,
            'generated_docker_compose' => "services:\n  app:\n    image: nginx\n",
            'saved_outputs' => new Collection,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($job, $value);
        }

        return [$job, $reflection];
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('replaces repository symlinks instead of writing the .env, compose, and README files through them', function (string $user) {
    $this->server->update(['user' => $user]);
    $linkTarget = "{$this->linkTargets}/repository-file";
    File::put($linkTarget, "original\n");
    // Links in the repository, and links that an earlier deployment copied into the resource directory.
    foreach (['.env', 'docker-compose.yaml', 'README.md'] as $name) {
        symlink($linkTarget, "{$this->workdir}/{$name}");
    }
    symlink($linkTarget, "{$this->configurationDir}/.env");

    [$job, $reflection] = ($this->makeJob)();
    $reflection->getMethod('save_runtime_environment_variables')->invoke($job);
    $reflection->getMethod('write_deployment_configurations')->invoke($job);

    expect(File::get($linkTarget))->toBe("original\n");
    foreach (['.env', 'docker-compose.yaml', 'README.md'] as $name) {
        expect(is_link("{$this->configurationDir}/{$name}"))->toBeFalse("{$name} is still a link");
    }
    expect(File::get("{$this->configurationDir}/.env"))->toContain('COOLIFY_CONTAINER_NAME="symlink-write-app"')
        ->and(File::get("{$this->workdir}/.env"))->toContain('COOLIFY_CONTAINER_NAME="symlink-write-app"')
        ->and(File::get("{$this->configurationDir}/docker-compose.yaml"))->toBe("services:\n  app:\n    image: nginx\n");
})->with(['root', 'cooluser']);

it('does not create a file through a dangling .env symlink', function () {
    $linkTarget = "{$this->linkTargets}/missing-file";
    symlink($linkTarget, "{$this->workdir}/.env");
    symlink($linkTarget, "{$this->configurationDir}/.env");

    [$job, $reflection] = ($this->makeJob)();
    $reflection->getMethod('save_runtime_environment_variables')->invoke($job);
    $reflection->getMethod('write_deployment_configurations')->invoke($job);

    expect(file_exists($linkTarget))->toBeFalse()
        ->and(is_link("{$this->configurationDir}/.env"))->toBeFalse()
        ->and(File::get("{$this->configurationDir}/.env"))->toContain('COOLIFY_CONTAINER_NAME="symlink-write-app"');
});

it('keeps the file that a relative repository symlink points to', function () {
    File::put("{$this->workdir}/docs.md", "# Docs\n");
    symlink('docs.md', "{$this->workdir}/README.md");

    [$job, $reflection] = ($this->makeJob)();
    $reflection->getMethod('write_deployment_configurations')->invoke($job);

    expect(File::get("{$this->configurationDir}/docs.md"))->toBe("# Docs\n")
        ->and(is_link("{$this->configurationDir}/README.md"))->toBeFalse();
});
