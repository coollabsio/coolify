<?php

namespace App\Services\GithubRunner;

use App\Actions\Server\InstallSysbox;
use App\Enums\GithubRunnerDockerMode;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;

/**
 * Builds the remote shell commands for one ephemeral runner. Each runner gets its own network,
 * volumes, and (in dind and sysbox modes) a private Docker daemon. The host Docker socket is never mounted.
 * Every command is a single line that is safe for parseCommandsByLineForSudo().
 */
class GithubRunnerContainer
{
    public const LABEL = 'coolify.githubRunner';

    public const EXECUTION_LABEL = 'coolify.githubRunnerExecution';

    public const SECRETS_DIRECTORY = '/data/coolify/github-runners';

    /** Group id of "docker" in the runner image. */
    private const RUNNER_DOCKER_GID = 123;

    private const RUNNER_UID = 1001;

    private const PIDS_LIMIT = 4096;

    public function __construct(private GithubRunnerExecution $execution, private GithubRunnerConfig $config) {}

    public static function nameFor(string $executionUuid): string
    {
        return 'coolify-runner-'.$executionUuid;
    }

    public function name(): string
    {
        return self::nameFor($this->execution->uuid);
    }

    public function dindName(): string
    {
        return $this->name().'-dind';
    }

    public function usesDind(): bool
    {
        return $this->config->docker_mode !== GithubRunnerDockerMode::None;
    }

    /**
     * Creates the network and volumes, pulls missing images, and starts the Docker sidecar.
     *
     * @return array<int, string>
     */
    public function prepareCommands(): array
    {
        $name = $this->name();
        $labels = $this->labelFlags();
        $commands = [
            "docker network create {$labels} {$name}",
            $this->pullOrUseLocal($this->config->runnerImage()),
        ];

        if (! $this->usesDind()) {
            return $commands;
        }

        foreach (['sock', 'work', 'externals'] as $volume) {
            $commands[] = "docker volume create {$labels} {$name}-{$volume}";
        }
        $commands[] = $this->pullIfMissing(config('constants.github_runner.dind_image'));
        $isolation = $this->config->docker_mode === GithubRunnerDockerMode::Sysbox
            ? '--runtime='.InstallSysbox::RUNTIME
            : '--privileged';
        $commands[] = "docker run -d {$isolation}"
            ." --name {$this->dindName()} --network {$name} {$labels} {$this->limitFlags()}"
            ." {$this->sharedVolumeFlags()} "
            .escapeshellarg(config('constants.github_runner.dind_image'))
            .' dockerd --host=unix:///var/run/docker.sock --group='.self::RUNNER_DOCKER_GID;

        return $commands;
    }

    /**
     * Prints the version of the sidecar Docker daemon once it accepts connections.
     */
    public function dockerReadyCommand(): string
    {
        return "docker exec {$this->dindName()} docker version --format '{{.Server.Version}}'";
    }

    /**
     * Writes the JIT config to a root-only env file, starts the runner, and deletes the file.
     * The secret is never part of the docker run command line.
     *
     * @return array<int, string>
     */
    public function startCommands(string $encodedJitConfig): array
    {
        $name = $this->name();
        $envFile = $this->envFile();
        $environment = base64_encode("ACTIONS_RUNNER_INPUT_JITCONFIG={$encodedJitConfig}\n");

        $commands = [
            'mkdir -p '.self::SECRETS_DIRECTORY,
            "echo {$environment} | base64 -d | tee {$envFile} > /dev/null",
            "chmod 600 {$envFile}",
        ];

        $runnerFlags = "--name {$name} --network {$name} {$this->labelFlags()} {$this->limitFlags()} --env-file {$envFile}";
        if ($this->usesDind()) {
            $commands[] = "docker exec {$this->dindName()} chown ".self::RUNNER_UID.':'.self::RUNNER_DOCKER_GID.' /home/runner/_work';
            $runnerFlags .= ' -e DOCKER_HOST=unix:///var/run/docker.sock -e RUNNER_WAIT_FOR_DOCKER_IN_SECONDS=120 '.$this->sharedVolumeFlags();
        }
        $commands[] = "docker run -d {$runnerFlags} ".escapeshellarg($this->config->runnerImage()).' /home/runner/run.sh';
        $commands[] = "rm -f {$envFile}";

        return $commands;
    }

    /**
     * Removes every Docker resource and the secret file of a runner. Missing resources are ignored.
     *
     * @return array<int, string>
     */
    public static function cleanupCommands(string $executionUuid): array
    {
        $name = self::nameFor($executionUuid);

        return [
            "docker rm -f -v {$name} {$name}-dind > /dev/null 2>&1 || true",
            "docker volume rm {$name}-sock {$name}-work {$name}-externals > /dev/null 2>&1 || true",
            "docker network rm {$name} > /dev/null 2>&1 || true",
            'rm -f '.self::SECRETS_DIRECTORY."/{$executionUuid}.env",
        ];
    }

    /**
     * Lists runner containers as "<execution uuid> <container name> <state>" lines.
     */
    public static function listCommand(): string
    {
        return 'docker ps -a --filter label='.self::LABEL.'=true --format \'{{.Label "'.self::EXECUTION_LABEL.'"}} {{.Names}} {{.State}}\'';
    }

    private function envFile(): string
    {
        return self::SECRETS_DIRECTORY."/{$this->execution->uuid}.env";
    }

    private function labelFlags(): string
    {
        return '--label '.self::LABEL.'=true --label '.self::EXECUTION_LABEL.'='.$this->execution->uuid;
    }

    private function limitFlags(): string
    {
        $flags = ['--pids-limit '.self::PIDS_LIMIT];
        if (filled($this->config->cpu_limit)) {
            $flags[] = '--cpus '.escapeshellarg($this->config->cpu_limit);
        }
        if (filled($this->config->memory_limit)) {
            $memory = escapeshellarg($this->config->memory_limit);
            $flags[] = "--memory {$memory} --memory-swap {$memory}";
        }

        return implode(' ', $flags);
    }

    private function sharedVolumeFlags(): string
    {
        $name = $this->name();

        return "-v {$name}-sock:/var/run -v {$name}-work:/home/runner/_work -v {$name}-externals:/home/runner/externals";
    }

    /**
     * Pulls the newest version of a moving tag such as "latest", or keeps the local image when the
     * registry cannot be reached.
     */
    private function pullOrUseLocal(string $image): string
    {
        $image = escapeshellarg($image);

        return "docker pull {$image} || docker image inspect {$image} > /dev/null";
    }

    private function pullIfMissing(string $image): string
    {
        $image = escapeshellarg($image);

        return "docker image inspect {$image} > /dev/null 2>&1 || docker pull {$image}";
    }
}
