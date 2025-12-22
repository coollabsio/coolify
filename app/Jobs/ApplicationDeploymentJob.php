<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationConfigurationChanged;
use App\Events\ServiceStatusChanged;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\ExecuteRemoteCommand;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, EnvironmentVariableAnalyzer, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    public const BUILD_TIME_ENV_PATH = '/artifacts/build-time.env';

    private const BUILD_SCRIPT_PATH = '/artifacts/build.sh';

    private const NIXPACKS_PLAN_PATH = '/artifacts/thegameplan.json';

    public $tries = 1;

    public $timeout = 3600;

    public static int $batch_counter = 0;

    private bool $newVersionIsHealthy = false;

    private ApplicationDeploymentQueue $application_deployment_queue;

    private Application $application;

    private string $deployment_uuid;

    private int $pull_request_id;

    private string $commit;

    private bool $rollback;

    private bool $force_rebuild;

    private bool $restart_only;

    private ?string $dockerImage = null;

    private ?string $dockerImageTag = null;

    private GithubApp|GitlabApp|string $source = 'other';

    private StandaloneDocker|SwarmDocker $destination;

    // Deploy to Server
    private Server $server;

    // Build Server
    private Server $build_server;

    private bool $use_build_server = false;

    // Save original server between phases
    private Server $original_server;

    private Server $mainServer;

    private bool $is_this_additional_server = false;

    private ?ApplicationPreview $preview = null;

    private ?string $git_type = null;

    private bool $only_this_server = false;

    private string $container_name;

    private ?string $currently_running_container_name = null;

    private string $basedir;

    private string $workdir;

    private ?string $build_pack = null;

    private string $configuration_dir;

    private string $build_image_name;

    private string $production_image_name;

    private bool $is_debug_enabled;

    private Collection|string $build_args;

    private $env_args;

    private $env_nixpacks_args;

    private $docker_compose;

    private $docker_compose_base64;

    private ?string $nixpacks_plan = null;

    private Collection $nixpacks_plan_json;

    private ?string $nixpacks_type = null;

    private string $dockerfile_location = '/Dockerfile';

    private string $docker_compose_location = '/docker-compose.yaml';

    private ?string $docker_compose_custom_start_command = null;

    private ?string $docker_compose_custom_build_command = null;

    private ?string $addHosts = null;

    private ?string $buildTarget = null;

    private bool $disableBuildCache = false;

    private Collection $saved_outputs;

    private ?string $secrets_hash_key = null;

    private ?string $full_healthcheck_url = null;

    private string $serverUser = 'root';

    private string $serverUserHomeDir = '/root';

    private string $dockerConfigFileExists = 'NOK';

    private int $customPort = 22;

    private ?string $customRepository = null;

    private ?string $fullRepoUrl = null;

    private ?string $branch = null;

    private ?string $coolify_variables = null;

    private bool $preserveRepository = false;

    private bool $dockerBuildkitSupported = false;

    private bool $skip_build = false;

    private Collection|string $build_secrets;

    public function tags()
    {
        // Do not remove this one, it needs to properly identify which worker is running the job
        return ['App\Models\ApplicationDeploymentQueue:'.$this->application_deployment_queue_id];
    }

    public function __construct(public int $application_deployment_queue_id)
    {
        $this->onQueue('high');

        $this->application_deployment_queue = ApplicationDeploymentQueue::find($this->application_deployment_queue_id);
        $this->nixpacks_plan_json = collect([]);

        $this->application = Application::find($this->application_deployment_queue->application_id);
        $this->build_pack = data_get($this->application, 'build_pack');
        $this->build_args = collect([]);
        $this->build_secrets = '';

        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->rollback = $this->application_deployment_queue->rollback;
        $this->disableBuildCache = $this->application->settings->disable_build_cache;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;
        if ($this->disableBuildCache) {
            $this->force_rebuild = true;
        }
        $this->restart_only = $this->application_deployment_queue->restart_only;
        $this->restart_only = $this->restart_only && $this->application->build_pack !== 'dockerimage' && $this->application->build_pack !== 'dockerfile';
        $this->only_this_server = $this->application_deployment_queue->only_this_server;

        $this->git_type = data_get($this->application_deployment_queue, 'git_type');

        $source = data_get($this->application, 'source');
        if ($source) {
            $this->source = $source->getMorphClass()::where('id', $this->application->source->id)->first();
        }
        $this->server = Server::find($this->application_deployment_queue->server_id);
        $this->timeout = $this->server->settings->dynamic_timeout;
        $this->destination = $this->server->destinations()->where('id', $this->application_deployment_queue->destination_id)->first();
        $this->server = $this->mainServer = $this->destination->server;
        $this->serverUser = $this->server->user;
        $this->is_this_additional_server = $this->application->additional_servers()->wherePivot('server_id', $this->server->id)->count() > 0;
        $this->preserveRepository = $this->application->settings->is_preserve_repository_enabled;

        $this->basedir = $this->application->generateBaseDir($this->deployment_uuid);
        $this->workdir = "{$this->basedir}".rtrim($this->application->base_directory, '/');
        $this->configuration_dir = application_configuration_dir()."/{$this->application->uuid}";
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;

        $this->container_name = generateApplicationContainerName($this->application, $this->pull_request_id);
        if ($this->application->settings->custom_internal_name && ! $this->application->settings->is_consistent_container_name_enabled) {
            if ($this->pull_request_id === 0) {
                $this->container_name = $this->application->settings->custom_internal_name;
            } else {
                $this->container_name = addPreviewDeploymentSuffix($this->application->settings->custom_internal_name, $this->pull_request_id);
            }
        }

        $this->saved_outputs = collect();

        // Set preview fqdn
        if ($this->pull_request_id !== 0) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
            if ($this->preview) {
                if ($this->application->build_pack === 'dockercompose') {
                    $this->preview->generate_preview_fqdn_compose();
                } else {
                    $this->preview->generate_preview_fqdn();
                }
            }
            if ($this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::IN_PROGRESS);
            }
            if ($this->application->build_pack === 'dockerfile') {
                if (data_get($this->application, 'dockerfile_location')) {
                    $this->dockerfile_location = $this->application->dockerfile_location;
                }
            }
        }
    }

    public function handle(): void
    {
        // Check if deployment was cancelled before we even started
        $this->application_deployment_queue->refresh();
        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->addLogEntry('Deployment was cancelled before starting.');

            return;
        }

        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'horizon_job_worker' => gethostname(),
        ]);
        if ($this->server->isFunctional() === false) {
            $this->application_deployment_queue->addLogEntry('Server is not functional.');
            $this->fail('Server is not functional.');

            return;
        }
        try {
            // Make sure the private key is stored in the filesystem
            $this->server->privateKey->storeInFileSystem();
            // Generate custom host<->ip mapping
            $allContainers = instant_remote_process(["docker network inspect {$this->destination->network} -f '{{json .Containers}}' "], $this->server);

            if (! is_null($allContainers)) {
                $allContainers = format_docker_command_output_to_json($allContainers);
                $ips = collect([]);
                if (count($allContainers) > 0) {
                    $allContainers = $allContainers[0];
                    $allContainers = collect($allContainers)->sort()->values();
                    foreach ($allContainers as $container) {
                        $containerName = data_get($container, 'Name');
                        if ($containerName === 'coolify-proxy') {
                            continue;
                        }
                        if (preg_match('/-(\d{12})/', $containerName)) {
                            continue;
                        }
                        $containerIp = data_get($container, 'IPv4Address');
                        if ($containerName && $containerIp) {
                            $containerIp = str($containerIp)->before('/');
                            $ips->put($containerName, $containerIp->value());
                        }
                    }
                }
                $this->addHosts = $ips->map(function ($ip, $name) {
                    return "--add-host $name:$ip";
                })->implode(' ');
            }

            if ($this->application->dockerfile_target_build) {
                $this->buildTarget = " --target {$this->application->dockerfile_target_build} ";
            }

            // Check custom port
            ['repository' => $this->customRepository, 'port' => $this->customPort] = $this->application->customRepository();

            if (data_get($this->application, 'settings.is_build_server_enabled')) {
                $teamId = data_get($this->application, 'environment.project.team.id');
                $buildServers = Server::buildServers($teamId)->get();
                if ($buildServers->count() === 0) {
                    $this->application_deployment_queue->addLogEntry('No suitable build server found. Using the deployment server.');
                    $this->build_server = $this->server;
                    $this->original_server = $this->server;
                } else {
                    $this->build_server = $buildServers->random();
                    $this->application_deployment_queue->build_server_id = $this->build_server->id;
                    $this->application_deployment_queue->addLogEntry("Found a suitable build server ({$this->build_server->name}).");
                    $this->original_server = $this->server;
                    $this->use_build_server = true;
                }
            } else {
                // Set build server & original_server to the same as deployment server
                $this->build_server = $this->server;
                $this->original_server = $this->server;
            }
            $this->detectBuildKitCapabilities();
            $this->decide_what_to_do();
        } catch (Exception $e) {
            if ($this->pull_request_id !== 0 && $this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::ERROR);
            }
            $this->fail($e);
            throw $e;
        } finally {
            // Wrap cleanup operations in try-catch to prevent exceptions from interfering
            // with Laravel's job failure handling and status updates
            try {
                $this->application_deployment_queue->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            } catch (Exception $e) {
                // Log but don't fail - finished_at is not critical
                \Log::warning('Failed to update finished_at for deployment '.$this->deployment_uuid.': '.$e->getMessage());
            }

            try {
                if ($this->use_build_server) {
                    $this->server = $this->build_server;
                } else {
                    $this->write_deployment_configurations();
                }
            } catch (Exception $e) {
                // Log but don't fail - configuration writing errors shouldn't prevent status updates
                $this->application_deployment_queue->addLogEntry('Warning: Failed to write deployment configurations: '.$e->getMessage(), 'stderr');
            }

            try {
                $this->application_deployment_queue->addLogEntry("Gracefully shutting down build container: {$this->deployment_uuid}");
                $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);
            } catch (Exception $e) {
                // Log but don't fail - container cleanup errors are expected when container is already gone
                \Log::warning('Failed to shutdown container '.$this->deployment_uuid.': '.$e->getMessage());
            }

            try {
                ServiceStatusChanged::dispatch(data_get($this->application, 'environment.project.team.id'));
            } catch (Exception $e) {
                // Log but don't fail - event dispatch errors shouldn't prevent status updates
                \Log::warning('Failed to dispatch ServiceStatusChanged for deployment '.$this->deployment_uuid.': '.$e->getMessage());
            }
        }
    }

    private function detectBuildKitCapabilities(): void
    {
        // If build secrets are not enabled, skip detection and use traditional args
        if (! $this->application->settings->use_build_secrets) {
            $this->dockerBuildkitSupported = false;

            return;
        }

        $serverToCheck = $this->use_build_server ? $this->build_server : $this->server;
        $serverName = $this->use_build_server ? "build server ({$serverToCheck->name})" : "deployment server ({$serverToCheck->name})";

        try {
            $dockerVersion = instant_remote_process(
                ["docker version --format '{{.Server.Version}}'"],
                $serverToCheck
            );

            $versionParts = explode('.', $dockerVersion);
            $majorVersion = (int) $versionParts[0];
            $minorVersion = (int) ($versionParts[1] ?? 0);

            if ($majorVersion < 18 || ($majorVersion == 18 && $minorVersion < 9)) {
                $this->dockerBuildkitSupported = false;
                $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} on {$serverName} does not support BuildKit (requires 18.09+). Build secrets feature disabled.");

                return;
            }

            $buildkitEnabled = instant_remote_process(
                ["docker buildx version >/dev/null 2>&1 && echo 'available' || echo 'not-available'"],
                $serverToCheck
            );

            if (trim($buildkitEnabled) !== 'available') {
                $buildkitTest = instant_remote_process(
                    ["DOCKER_BUILDKIT=1 docker build --help 2>&1 | grep -q 'secret' && echo 'supported' || echo 'not-supported'"],
                    $serverToCheck
                );

                if (trim($buildkitTest) === 'supported') {
                    $this->dockerBuildkitSupported = true;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} with BuildKit secrets support detected on {$serverName}.");
                    $this->application_deployment_queue->addLogEntry('Build secrets are enabled and will be used for enhanced security.');
                } else {
                    $this->dockerBuildkitSupported = false;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} on {$serverName} does not have BuildKit secrets support.");
                    $this->application_deployment_queue->addLogEntry('Build secrets feature is enabled but not supported. Using traditional build arguments.');
                }
            } else {
                // Buildx is available, which means BuildKit is available
                // Now specifically test for secrets support
                $secretsTest = instant_remote_process(
                    ["docker build --help 2>&1 | grep -q 'secret' && echo 'supported' || echo 'not-supported'"],
                    $serverToCheck
                );

                if (trim($secretsTest) === 'supported') {
                    $this->dockerBuildkitSupported = true;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} with BuildKit and Buildx detected on {$serverName}.");
                    $this->application_deployment_queue->addLogEntry('Build secrets are enabled and will be used for enhanced security.');
                } else {
                    $this->dockerBuildkitSupported = false;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} with Buildx on {$serverName}, but secrets not supported.");
                    $this->application_deployment_queue->addLogEntry('Build secrets feature is enabled but not supported. Using traditional build arguments.');
                }
            }
        } catch (\Exception $e) {
            $this->dockerBuildkitSupported = false;
            $this->application_deployment_queue->addLogEntry("Could not detect BuildKit capabilities on {$serverName}: {$e->getMessage()}");
            $this->application_deployment_queue->addLogEntry('Build secrets feature is enabled but detection failed. Using traditional build arguments.');
        }
    }

    private function decide_what_to_do()
    {
        if ($this->restart_only) {
            $this->just_restart();

            return;
        } elseif ($this->pull_request_id !== 0) {
            $this->deploy_pull_request();
        } elseif ($this->application->dockerfile) {
            $this->deploy_simple_dockerfile();
        } elseif ($this->application->build_pack === 'dockercompose') {
            $this->deploy_docker_compose_buildpack();
        } elseif ($this->application->build_pack === 'dockerimage') {
            $this->deploy_dockerimage_buildpack();
        } elseif ($this->application->build_pack === 'dockerfile') {
            $this->deploy_dockerfile_buildpack();
        } elseif ($this->application->build_pack === 'static') {
            $this->deploy_static_buildpack();
        } else {
            $this->deploy_nixpacks_buildpack();
        }
        $this->post_deployment();
    }

    private function post_deployment()
    {
        // Mark deployment as complete FIRST, before any other operations
        // This ensures the deployment status is FINISHED even if subsequent operations fail
        $this->completeDeployment();

        // Then handle side effects - these should not fail the deployment
        try {
            GetContainersStatus::dispatch($this->server);
        } catch (\Exception $e) {
            \Log::warning('Failed to dispatch GetContainersStatus for deployment '.$this->deployment_uuid.': '.$e->getMessage());
        }

        if ($this->pull_request_id !== 0) {
            if ($this->application->is_github_based()) {
                try {
                    ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::FINISHED);
                } catch (\Exception $e) {
                    \Log::warning('Failed to dispatch PR update for deployment '.$this->deployment_uuid.': '.$e->getMessage());
                }
            }
        }

        try {
            $this->run_post_deployment_command();
        } catch (\Exception $e) {
            \Log::warning('Post deployment command failed for '.$this->deployment_uuid.': '.$e->getMessage());
        }

        try {
            $this->application->isConfigurationChanged(true);
        } catch (\Exception $e) {
            \Log::warning('Failed to mark configuration as changed for deployment '.$this->deployment_uuid.': '.$e->getMessage());
        }
    }

    private function deploy_simple_dockerfile()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $dockerfile_base64 = base64_encode($this->application->dockerfile);
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->application->name} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$dockerfile_base64' | base64 -d | tee {$this->workdir}{$this->dockerfile_location} > /dev/null"),
            ],
        );
        $this->generate_image_names();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->rolling_update();
    }

    private function deploy_dockerimage_buildpack()
    {
        $this->dockerImage = $this->application->docker_registry_image_name;
        if (str($this->application->docker_registry_image_tag)->isEmpty()) {
            $this->dockerImageTag = 'latest';
        } else {
            $this->dockerImageTag = $this->application->docker_registry_image_tag;
        }

        // Check if this is an image hash deployment
        $isImageHash = str($this->dockerImageTag)->startsWith('sha256-');
        $displayName = $isImageHash ? "{$this->dockerImage}@sha256:".str($this->dockerImageTag)->after('sha256-') : "{$this->dockerImage}:{$this->dockerImageTag}";

        $this->application_deployment_queue->addLogEntry("Starting deployment of {$displayName} to {$this->server->name}.");
        $this->generate_image_names();
        $this->prepare_builder_image();
        $this->generate_compose_file();

        // Save runtime environment variables (including empty .env file if no variables defined)
        $this->save_runtime_environment_variables();

        $this->rolling_update();
    }

    private function deploy_docker_compose_buildpack()
    {
        if (data_get($this->application, 'docker_compose_location')) {
            $this->docker_compose_location = $this->application->docker_compose_location;
        }
        if (data_get($this->application, 'docker_compose_custom_start_command')) {
            $this->docker_compose_custom_start_command = $this->application->docker_compose_custom_start_command;
            if (! str($this->docker_compose_custom_start_command)->contains('--project-directory')) {
                $this->docker_compose_custom_start_command = str($this->docker_compose_custom_start_command)->replaceFirst('compose', 'compose --project-directory '.$this->workdir)->value();
            }
        }
        if (data_get($this->application, 'docker_compose_custom_build_command')) {
            $this->docker_compose_custom_build_command = $this->application->docker_compose_custom_build_command;
            if (! str($this->docker_compose_custom_build_command)->contains('--project-directory')) {
                $this->docker_compose_custom_build_command = str($this->docker_compose_custom_build_command)->replaceFirst('compose', 'compose --project-directory '.$this->workdir)->value();
            }
        }
        if ($this->pull_request_id === 0) {
            $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->application->name} to {$this->server->name}.");
        } else {
            $this->application_deployment_queue->addLogEntry("Starting pull request (#{$this->pull_request_id}) deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        }
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->clone_repository();
        if ($this->preserveRepository) {
            foreach ($this->application->fileStorages as $fileStorage) {
                $path = $fileStorage->fs_path;
                $saveName = 'file_stat_'.$fileStorage->id;
                $realPathInGit = str($path)->replace($this->application->workdir(), $this->workdir)->value();
                // check if the file is a directory or a file inside the repository
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "stat -c '%F' {$realPathInGit}"), 'hidden' => true, 'ignore_errors' => true, 'save' => $saveName]
                );
                if ($this->saved_outputs->has($saveName)) {
                    $fileStat = $this->saved_outputs->get($saveName);
                    if ($fileStat->value() === 'directory' && ! $fileStorage->is_directory) {
                        $fileStorage->is_directory = true;
                        $fileStorage->content = null;
                        $fileStorage->save();
                        $fileStorage->deleteStorageOnServer();
                        $fileStorage->saveStorageOnServer();
                    } elseif ($fileStat->value() === 'regular file' && $fileStorage->is_directory) {
                        $fileStorage->is_directory = false;
                        $fileStorage->is_based_on_git = true;
                        $fileStorage->save();
                        $fileStorage->deleteStorageOnServer();
                        $fileStorage->saveStorageOnServer();
                    }
                }
            }
        }
        $this->generate_image_names();
        $this->cleanup_git();

        $this->generate_build_env_variables();

        $this->application->loadComposeFile(isInit: false);
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            $this->application->oldRawParser();
            $yaml = $composeFile = $this->application->docker_compose_raw;

            // For raw compose, we cannot automatically add secrets configuration
            // User must define it manually in their docker-compose file
            if ($this->application->settings->use_build_secrets && $this->dockerBuildkitSupported && ! empty($this->build_secrets)) {
                $this->application_deployment_queue->addLogEntry('Build secrets are configured. Ensure your docker-compose file includes build.secrets configuration for services that need them.');
            }
        } else {
            $composeFile = $this->application->parse(pull_request_id: $this->pull_request_id, preview_id: data_get($this->preview, 'id'), commit: $this->commit);
            // Always add .env file to services
            $services = collect(data_get($composeFile, 'services', []));
            $services = $services->map(function ($service, $name) {
                $service['env_file'] = ['.env'];

                return $service;
            });
            $composeFile['services'] = $services->toArray();
            if (empty($composeFile)) {
                $this->application_deployment_queue->addLogEntry('Failed to parse docker-compose file.');
                $this->fail('Failed to parse docker-compose file.');

                return;
            }

            // Add build secrets to compose file if enabled and BuildKit is supported
            if ($this->application->settings->use_build_secrets && $this->dockerBuildkitSupported && ! empty($this->build_secrets)) {
                $composeFile = $this->add_build_secrets_to_compose($composeFile);
            }

            $yaml = Yaml::dump(convertToArray($composeFile), 10);
        }
        $this->docker_compose_base64 = base64_encode($yaml);
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}{$this->docker_compose_location} > /dev/null"),
            'hidden' => true,
        ]);

        // Modify Dockerfiles for ARGs and build secrets
        $this->modify_dockerfiles_for_compose($composeFile);
        // Build new container to limit downtime.
        $this->application_deployment_queue->addLogEntry('Pulling & building required images.');

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        if ($this->docker_compose_custom_build_command) {
            // Auto-inject -f (compose file) and --env-file flags using helper function
            $build_command = injectDockerComposeFlags(
                $this->docker_compose_custom_build_command,
                "{$this->workdir}{$this->docker_compose_location}",
                self::BUILD_TIME_ENV_PATH
            );

            // Prepend DOCKER_BUILDKIT=1 if BuildKit is supported
            if ($this->dockerBuildkitSupported) {
                $build_command = "DOCKER_BUILDKIT=1 {$build_command}";
            }

            // Inject build arguments after build subcommand if not using build secrets
            if (! $this->application->settings->use_build_secrets && $this->build_args instanceof \Illuminate\Support\Collection && $this->build_args->isNotEmpty()) {
                $build_args_string = $this->build_args->implode(' ');
                // Escape single quotes for bash -c context used by executeInDocker
                $build_args_string = str_replace("'", "'\\''", $build_args_string);

                // Inject build args right after 'build' subcommand (not at the end)
                $original_command = $build_command;
                $build_command = injectDockerComposeBuildArgs($build_command, $build_args_string);

                // Only log if build args were actually injected (command was modified)
                if ($build_command !== $original_command) {
                    $this->application_deployment_queue->addLogEntry('Adding build arguments to custom Docker Compose build command.');
                }
            }

            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$build_command}"), 'hidden' => true],
            );
        } else {
            $command = "{$this->coolify_variables} docker compose";
            // Prepend DOCKER_BUILDKIT=1 if BuildKit is supported
            if ($this->dockerBuildkitSupported) {
                $command = "DOCKER_BUILDKIT=1 {$command}";
            }
            // Use build-time .env file from /artifacts (outside Docker context to prevent it from being in the image)
            $command .= ' --env-file '.self::BUILD_TIME_ENV_PATH;
            if ($this->force_rebuild) {
                $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} build --pull --no-cache";
            } else {
                $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} build --pull";
            }

            if (! $this->application->settings->use_build_secrets && $this->build_args instanceof \Illuminate\Support\Collection && $this->build_args->isNotEmpty()) {
                $build_args_string = $this->build_args->implode(' ');
                // Escape single quotes for bash -c context used by executeInDocker
                $build_args_string = str_replace("'", "'\\''", $build_args_string);
                $command .= " {$build_args_string}";
                $this->application_deployment_queue->addLogEntry('Adding build arguments to Docker Compose build command.');
            }

            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, $command), 'hidden' => true],
            );
        }

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->stop_running_container(force: true);
        $this->application_deployment_queue->addLogEntry('Starting new application.');
        $networkId = $this->application->uuid;
        if ($this->pull_request_id !== 0) {
            $networkId = "{$this->application->uuid}-{$this->pull_request_id}";
        }
        if ($this->server->isSwarm()) {
            // TODO
        } else {
            $this->execute_remote_command([
                "docker network inspect '{$networkId}' >/dev/null 2>&1 || docker network create --attachable '{$networkId}' >/dev/null || true",
                'hidden' => true,
                'ignore_errors' => true,
            ], [
                "docker network connect {$networkId} coolify-proxy >/dev/null 2>&1 || true",
                'hidden' => true,
                'ignore_errors' => true,
            ]);
        }

        // Start compose file
        $server_workdir = $this->application->workdir();
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            if ($this->docker_compose_custom_start_command) {
                // Auto-inject -f (compose file) and --env-file flags using helper function
                $start_command = injectDockerComposeFlags(
                    $this->docker_compose_custom_start_command,
                    "{$server_workdir}{$this->docker_compose_location}",
                    "{$server_workdir}/.env"
                );

                $this->write_deployment_configurations();
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "cd {$this->workdir} && {$start_command}"), 'hidden' => true],
                );
            } else {
                $this->write_deployment_configurations();
                $this->docker_compose_location = '/docker-compose.yaml';

                $command = "{$this->coolify_variables} docker compose";
                // Always use .env file
                $command .= " --env-file {$server_workdir}/.env";
                $command .= " --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                $this->execute_remote_command(
                    ['command' => $command, 'hidden' => true],
                );
            }
        } else {
            if ($this->docker_compose_custom_start_command) {
                // Auto-inject -f (compose file) and --env-file flags using helper function
                // Use $this->workdir for non-preserve-repository mode
                $workdir_path = $this->preserveRepository ? $server_workdir : $this->workdir;
                $start_command = injectDockerComposeFlags(
                    $this->docker_compose_custom_start_command,
                    "{$workdir_path}{$this->docker_compose_location}",
                    "{$workdir_path}/.env"
                );

                $this->write_deployment_configurations();
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$start_command}"), 'hidden' => true],
                );
            } else {
                $command = "{$this->coolify_variables} docker compose";
                if ($this->preserveRepository) {
                    // Always use .env file
                    $command .= " --env-file {$server_workdir}/.env";
                    $command .= " --project-name {$this->application->uuid} --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                    $this->write_deployment_configurations();

                    $this->execute_remote_command(
                        ['command' => $command, 'hidden' => true],
                    );
                } else {
                    // Always use .env file
                    $command .= " --env-file {$this->workdir}/.env";
                    $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} up -d";
                    $this->execute_remote_command(
                        [executeInDocker($this->deployment_uuid, $command), 'hidden' => true],
                    );
                    $this->write_deployment_configurations();
                }
            }
        }

        $this->application_deployment_queue->addLogEntry('New container started.');
    }

    private function deploy_dockerfile_buildpack()
    {
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        if (data_get($this->application, 'dockerfile_location')) {
            $this->dockerfile_location = $this->application->dockerfile_location;
        }
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        $this->clone_repository();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->cleanup_git();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->rolling_update();
    }

    private function deploy_nixpacks_buildpack()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_nixpacks_confs();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build for Nixpacks
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->build_image();

        // For Nixpacks, save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();
        $this->push_to_docker_registry();
        $this->rolling_update();
    }

    private function deploy_static_buildpack()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->build_static_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->rolling_update();
    }

    private function write_deployment_configurations()
    {
        if ($this->preserveRepository) {
            if ($this->use_build_server) {
                $this->server = $this->original_server;
            }
            if (str($this->configuration_dir)->isNotEmpty()) {
                $this->execute_remote_command(
                    [
                        "mkdir -p $this->configuration_dir",
                    ],
                    [
                        "docker cp {$this->deployment_uuid}:{$this->workdir}/. {$this->configuration_dir}",
                    ],
                );
            }
            foreach ($this->application->fileStorages as $fileStorage) {
                if (! $fileStorage->is_based_on_git && ! $fileStorage->is_directory) {
                    $fileStorage->saveStorageOnServer();
                }
            }
            if ($this->use_build_server) {
                $this->server = $this->build_server;
            }
        }
        if (isset($this->docker_compose_base64)) {
            if ($this->use_build_server) {
                $this->server = $this->original_server;
            }
            $readme = generate_readme_file($this->application->name, $this->application_deployment_queue->updated_at);

            $mainDir = $this->configuration_dir;
            if ($this->application->settings->is_raw_compose_deployment_enabled) {
                $mainDir = $this->application->workdir();
            }
            if ($this->pull_request_id === 0) {
                $composeFileName = "$mainDir/docker-compose.yaml";
            } else {
                $composeFileName = "$mainDir/".addPreviewDeploymentSuffix('docker-compose', $this->pull_request_id).'.yaml';
                $this->docker_compose_location = '/'.addPreviewDeploymentSuffix('docker-compose', $this->pull_request_id).'.yaml';
            }
            $this->execute_remote_command(
                [
                    "mkdir -p $mainDir",
                ],
                [
                    "echo '{$this->docker_compose_base64}' | base64 -d | tee $composeFileName > /dev/null",
                ],
                [
                    "echo '{$readme}' > $mainDir/README.md",
                ]
            );
            if ($this->use_build_server) {
                $this->server = $this->build_server;
            }
        }
    }

    private function push_to_docker_registry()
    {
        $forceFail = true;
        if (str($this->application->docker_registry_image_name)->isEmpty()) {
            return;
        }
        if ($this->restart_only) {
            return;
        }
        if ($this->application->build_pack === 'dockerimage') {
            return;
        }
        if ($this->use_build_server) {
            $forceFail = true;
        }
        if ($this->server->isSwarm() && $this->build_pack !== 'dockerimage') {
            $forceFail = true;
        }
        if ($this->application->additional_servers->count() > 0) {
            $forceFail = true;
        }
        if ($this->is_this_additional_server) {
            return;
        }
        try {
            instant_remote_process(["docker images --format '{{json .}}' {$this->production_image_name}"], $this->server);
            $this->application_deployment_queue->addLogEntry('----------------------------------------');
            $this->application_deployment_queue->addLogEntry("Pushing image to docker registry ({$this->production_image_name}).");
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "docker push {$this->production_image_name}"),
                    'hidden' => true,
                ],
            );
            if ($this->application->docker_registry_image_tag) {
                // Tag image with docker_registry_image_tag
                $this->application_deployment_queue->addLogEntry("Tagging and pushing image with {$this->application->docker_registry_image_tag} tag.");
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "docker tag {$this->production_image_name} {$this->application->docker_registry_image_name}:{$this->application->docker_registry_image_tag}"),
                        'ignore_errors' => true,
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, "docker push {$this->application->docker_registry_image_name}:{$this->application->docker_registry_image_tag}"),
                        'ignore_errors' => true,
                        'hidden' => true,
                    ],
                );
            }
        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry('Failed to push image to docker registry. Please check debug logs for more information.');
            if ($forceFail) {
                throw new DeploymentException(get_class($e).': '.$e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    private function generate_image_names()
    {
        if ($this->application->dockerfile) {
            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:build";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:latest";
            } else {
                $this->build_image_name = "{$this->application->uuid}:build";
                $this->production_image_name = "{$this->application->uuid}:latest";
            }
        } elseif ($this->application->build_pack === 'dockerimage') {
            // Check if this is an image hash deployment
            if (str($this->dockerImageTag)->startsWith('sha256-')) {
                $hash = str($this->dockerImageTag)->after('sha256-');
                $this->production_image_name = "{$this->dockerImage}@sha256:{$hash}";
            } else {
                $this->production_image_name = "{$this->dockerImage}:{$this->dockerImageTag}";
            }
        } elseif ($this->pull_request_id !== 0) {
            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:pr-{$this->pull_request_id}-build";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:pr-{$this->pull_request_id}";
            } else {
                $this->build_image_name = "{$this->application->uuid}:pr-{$this->pull_request_id}-build";
                $this->production_image_name = "{$this->application->uuid}:pr-{$this->pull_request_id}";
            }
        } else {
            $this->dockerImageTag = str($this->commit)->substr(0, 128);
            // if ($this->application->docker_registry_image_tag) {
            //     $this->dockerImageTag = $this->application->docker_registry_image_tag;
            // }
            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:{$this->dockerImageTag}-build";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:{$this->dockerImageTag}";
            } else {
                $this->build_image_name = "{$this->application->uuid}:{$this->dockerImageTag}-build";
                $this->production_image_name = "{$this->application->uuid}:{$this->dockerImageTag}";
            }
        }
    }

    private function just_restart()
    {
        $this->application_deployment_queue->addLogEntry("Restarting {$this->customRepository}:{$this->application->git_branch} on {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        $this->check_image_locally_or_remotely();
        $this->should_skip_build();
        $this->completeDeployment();
    }

    private function should_skip_build()
    {
        if (str($this->saved_outputs->get('local_image_found'))->isNotEmpty()) {
            if ($this->is_this_additional_server) {
                $this->skip_build = true;
                $this->application_deployment_queue->addLogEntry("Image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->generate_compose_file();

                // Save runtime environment variables even when skipping build
                $this->save_runtime_environment_variables();

                $this->push_to_docker_registry();
                $this->rolling_update();

                return true;
            }
            if (! $this->application->isConfigurationChanged()) {
                $this->application_deployment_queue->addLogEntry("No configuration changed & image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->skip_build = true;
                $this->generate_compose_file();

                // Save runtime environment variables even when skipping build
                $this->save_runtime_environment_variables();

                $this->push_to_docker_registry();
                $this->rolling_update();

                return true;
            } else {
                $this->application_deployment_queue->addLogEntry('Configuration changed. Rebuilding image.');
            }
        } else {
            $this->application_deployment_queue->addLogEntry("Image not found ({$this->production_image_name}). Building new image.");
        }
        if ($this->restart_only) {
            $this->restart_only = false;
            $this->decide_what_to_do();
        }

        return false;
    }

    private function check_image_locally_or_remotely()
    {
        $this->execute_remote_command([
            "docker images -q {$this->production_image_name} 2>/dev/null",
            'hidden' => true,
            'save' => 'local_image_found',
        ]);
        if (str($this->saved_outputs->get('local_image_found'))->isEmpty() && $this->application->docker_registry_image_name) {
            $this->execute_remote_command([
                "docker pull {$this->production_image_name} 2>/dev/null",
                'ignore_errors' => true,
                'hidden' => true,
            ]);
            $this->execute_remote_command([
                "docker images -q {$this->production_image_name} 2>/dev/null",
                'hidden' => true,
                'save' => 'local_image_found',
            ]);
        }
    }

    private function generate_runtime_environment_variables()
    {
        $envs = collect([]);
        $sort = $this->application->settings->is_env_sorting_enabled;
        if ($sort) {
            $sorted_environment_variables = $this->application->environment_variables->sortBy('key');
            $sorted_environment_variables_preview = $this->application->environment_variables_preview->sortBy('key');
        } else {
            $sorted_environment_variables = $this->application->environment_variables->sortBy('id');
            $sorted_environment_variables_preview = $this->application->environment_variables_preview->sortBy('id');
        }
        if ($this->build_pack === 'dockercompose') {
            $sorted_environment_variables = $sorted_environment_variables->filter(function ($env) {
                return ! str($env->key)->startsWith('SERVICE_FQDN_') && ! str($env->key)->startsWith('SERVICE_URL_') && ! str($env->key)->startsWith('SERVICE_NAME_');
            });
            $sorted_environment_variables_preview = $sorted_environment_variables_preview->filter(function ($env) {
                return ! str($env->key)->startsWith('SERVICE_FQDN_') && ! str($env->key)->startsWith('SERVICE_URL_') && ! str($env->key)->startsWith('SERVICE_NAME_');
            });
        }
        $ports = $this->application->main_port();
        $coolify_envs = $this->generate_coolify_env_variables();
        $coolify_envs->each(function ($item, $key) use ($envs) {
            $envs->push($key.'='.$item);
        });
        if ($this->pull_request_id === 0) {
            // Generate SERVICE_ variables first for dockercompose
            if ($this->build_pack === 'dockercompose') {
                $domains = collect(json_decode($this->application->docker_compose_domains)) ?? collect([]);

                // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs->push('SERVICE_URL_'.str($forServiceName)->upper().'='.$coolifyUrl->__toString());
                        $envs->push('SERVICE_FQDN_'.str($forServiceName)->upper().'='.$coolifyFqdn);
                    }
                }

                // Generate SERVICE_NAME for dockercompose services from processed compose
                if ($this->application->settings->is_raw_compose_deployment_enabled) {
                    $dockerCompose = Yaml::parse($this->application->docker_compose_raw);
                } else {
                    $dockerCompose = Yaml::parse($this->application->docker_compose);
                }
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($serviceName)->upper().'='.$serviceName);
                }
            }

            // Filter runtime variables (only include variables that are available at runtime)
            $runtime_environment_variables = $sorted_environment_variables->filter(function ($env) {
                return $env->is_runtime;
            });

            // Sort runtime environment variables: those referencing SERVICE_ variables come after others
            $runtime_environment_variables = $runtime_environment_variables->sortBy(function ($env) {
                if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->contains('${SERVICE_')) {
                    return 2;
                }

                return 1;
            });

            foreach ($runtime_environment_variables as $env) {
                $envs->push($env->key.'='.$env->real_value);
            }

            // Check for PORT environment variable mismatch with ports_exposes
            if ($this->build_pack !== 'dockercompose') {
                $detectedPort = $this->application->detectPortFromEnvironment(false);
                if ($detectedPort && ! empty($ports) && ! in_array($detectedPort, $ports)) {
                    $this->application_deployment_queue->addLogEntry(
                        "Warning: PORT environment variable ({$detectedPort}) does not match configured ports_exposes: ".implode(',', $ports).'. It could case "bad gateway" or "no server" errors. Check the "General" page to fix it.',
                        'stderr'
                    );
                }
            }

            // Add PORT if not exists, use the first port as default
            if ($this->build_pack !== 'dockercompose') {
                if ($this->application->environment_variables->where('key', 'PORT')->isEmpty()) {
                    $envs->push("PORT={$ports[0]}");
                }
            }
            // Add HOST if not exists
            if ($this->application->environment_variables->where('key', 'HOST')->isEmpty()) {
                $envs->push('HOST=0.0.0.0');
            }
        } else {
            // Generate SERVICE_ variables first for dockercompose preview
            if ($this->build_pack === 'dockercompose') {
                $domains = collect(json_decode(data_get($this->preview, 'docker_compose_domains'))) ?? collect([]);

                // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs->push('SERVICE_URL_'.str($forServiceName)->upper().'='.$coolifyUrl->__toString());
                        $envs->push('SERVICE_FQDN_'.str($forServiceName)->upper().'='.$coolifyFqdn);
                    }
                }

                // Generate SERVICE_NAME for dockercompose services
                $rawDockerCompose = Yaml::parse($this->application->docker_compose_raw);
                $rawServices = data_get($rawDockerCompose, 'services', []);
                foreach ($rawServices as $rawServiceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($rawServiceName)->upper().'='.addPreviewDeploymentSuffix($rawServiceName, $this->pull_request_id));
                }
            }

            // Filter runtime variables for preview (only include variables that are available at runtime)
            $runtime_environment_variables_preview = $sorted_environment_variables_preview->filter(function ($env) {
                return $env->is_runtime;
            });

            // Sort runtime environment variables: those referencing SERVICE_ variables come after others
            $runtime_environment_variables_preview = $runtime_environment_variables_preview->sortBy(function ($env) {
                if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->contains('${SERVICE_')) {
                    return 2;
                }

                return 1;
            });

            foreach ($runtime_environment_variables_preview as $env) {
                $envs->push($env->key.'='.$env->real_value);
            }
            // Add PORT if not exists, use the first port as default
            if ($this->build_pack !== 'dockercompose') {
                if ($this->application->environment_variables_preview->where('key', 'PORT')->isEmpty()) {
                    $envs->push("PORT={$ports[0]}");
                }
            }
            // Add HOST if not exists
            if ($this->application->environment_variables_preview->where('key', 'HOST')->isEmpty()) {
                $envs->push('HOST=0.0.0.0');
            }
        }

        // Return the generated environment variables instead of storing them globally
        return $envs;
    }

    private function save_runtime_environment_variables()
    {
        // This method saves the .env file with ALL runtime variables
        // For builds, it should be called AFTER the build to include runtime-only variables

        // Generate runtime environment variables locally
        $environment_variables = $this->generate_runtime_environment_variables();

        // Handle empty environment variables
        if ($environment_variables->isEmpty()) {
            // For Docker Compose and Docker Image, we need to create an empty .env file
            // because we always reference it in the compose file
            if ($this->build_pack === 'dockercompose' || $this->build_pack === 'dockerimage') {
                $this->application_deployment_queue->addLogEntry('Creating empty .env file (no environment variables defined).');

                // Create empty .env file
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "touch $this->workdir/.env"),
                    ]
                );

                // Also create in configuration directory
                if ($this->use_build_server) {
                    $this->server = $this->original_server;
                    $this->execute_remote_command(
                        [
                            "touch $this->configuration_dir/.env",
                        ]
                    );
                    $this->server = $this->build_server;
                } else {
                    $this->execute_remote_command(
                        [
                            "touch $this->configuration_dir/.env",
                        ]
                    );
                }
            } else {
                // For non-Docker Compose deployments, clean up any existing .env files
                if ($this->use_build_server) {
                    $this->server = $this->original_server;
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                    $this->server = $this->build_server;
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                } else {
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                }
            }

            return;
        }

        // Write the environment variables to file
        $envs_base64 = base64_encode($environment_variables->implode("\n"));

        // Write .env file to workdir (for container runtime)
        $this->application_deployment_queue->addLogEntry('Creating .env file with runtime variables for container.', hidden: true);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d | tee $this->workdir/.env > /dev/null"),
            ],
            [
                executeInDocker($this->deployment_uuid, "cat $this->workdir/.env"),
                'hidden' => true,

            ]
        );

        // Write .env file to configuration directory
        if ($this->use_build_server) {
            $this->server = $this->original_server;
            $this->execute_remote_command(
                [
                    "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/.env > /dev/null",
                ]
            );
            $this->server = $this->build_server;
        } else {
            $this->execute_remote_command(
                [
                    "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/.env > /dev/null",
                ]
            );
        }
    }

    private function generate_buildtime_environment_variables()
    {
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] Generating build-time environment variables');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
        }

        // Use associative array for automatic deduplication
        $envs_dict = [];

        // 1. Add nixpacks plan variables FIRST (lowest priority - can be overridden)
        if ($this->build_pack === 'nixpacks' &&
            isset($this->nixpacks_plan_json) &&
            $this->nixpacks_plan_json->isNotEmpty()) {

            $planVariables = data_get($this->nixpacks_plan_json, 'variables', []);

            if (! empty($planVariables)) {
                if (isDev()) {
                    $this->application_deployment_queue->addLogEntry('[DEBUG] Adding '.count($planVariables).' nixpacks plan variables to buildtime.env');
                }

                foreach ($planVariables as $key => $value) {
                    // Skip COOLIFY_* and SERVICE_* - they'll be added later with higher priority
                    if (str_starts_with($key, 'COOLIFY_') || str_starts_with($key, 'SERVICE_')) {
                        continue;
                    }

                    $escapedValue = escapeBashEnvValue($value);
                    $envs_dict[$key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Nixpacks var: {$key}={$escapedValue}");
                    }
                }
            }
        }

        // 2. Add COOLIFY variables (can override nixpacks, but shouldn't happen in practice)
        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        foreach ($coolify_envs as $key => $item) {
            $envs_dict[$key] = escapeBashEnvValue($item);
        }

        // 3. Add SERVICE_NAME, SERVICE_FQDN, SERVICE_URL variables for Docker Compose builds
        if ($this->build_pack === 'dockercompose') {
            if ($this->pull_request_id === 0) {
                // Generate SERVICE_NAME for dockercompose services from processed compose
                if ($this->application->settings->is_raw_compose_deployment_enabled) {
                    $dockerCompose = Yaml::parse($this->application->docker_compose_raw);
                } else {
                    $dockerCompose = Yaml::parse($this->application->docker_compose);
                }
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs_dict['SERVICE_NAME_'.str($serviceName)->upper()] = escapeBashEnvValue($serviceName);
                }

                // Generate SERVICE_FQDN & SERVICE_URL for non-PR deployments
                $domains = collect(json_decode($this->application->docker_compose_domains)) ?? collect([]);
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs_dict['SERVICE_URL_'.str($forServiceName)->upper()] = escapeBashEnvValue($coolifyUrl->__toString());
                        $envs_dict['SERVICE_FQDN_'.str($forServiceName)->upper()] = escapeBashEnvValue($coolifyFqdn);
                    }
                }
            } else {
                // Generate SERVICE_NAME for preview deployments
                $rawDockerCompose = Yaml::parse($this->application->docker_compose_raw);
                $rawServices = data_get($rawDockerCompose, 'services', []);
                foreach ($rawServices as $rawServiceName => $_) {
                    $envs_dict['SERVICE_NAME_'.str($rawServiceName)->upper()] = escapeBashEnvValue(addPreviewDeploymentSuffix($rawServiceName, $this->pull_request_id));
                }

                // Generate SERVICE_FQDN & SERVICE_URL for preview deployments with PR-specific domains
                $domains = collect(json_decode(data_get($this->preview, 'docker_compose_domains'))) ?? collect([]);
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs_dict['SERVICE_URL_'.str($forServiceName)->upper()] = escapeBashEnvValue($coolifyUrl->__toString());
                        $envs_dict['SERVICE_FQDN_'.str($forServiceName)->upper()] = escapeBashEnvValue($coolifyFqdn);
                    }
                }
            }
        }

        // 4. Add user-defined build-time variables LAST (highest priority - can override everything)
        if ($this->pull_request_id === 0) {
            $sorted_environment_variables = $this->application->environment_variables()
                ->where('is_buildtime', true)  // ONLY build-time variables
                ->orderBy($this->application->settings->is_env_sorting_enabled ? 'key' : 'id')
                ->get();

            // For Docker Compose, filter out SERVICE_FQDN and SERVICE_URL as we generate these
            if ($this->build_pack === 'dockercompose') {
                $sorted_environment_variables = $sorted_environment_variables->filter(function ($env) {
                    return ! str($env->key)->startsWith('SERVICE_FQDN_') && ! str($env->key)->startsWith('SERVICE_URL_');
                });
            }

            foreach ($sorted_environment_variables as $env) {
                // For literal/multiline vars, real_value includes quotes that we need to remove
                if ($env->is_literal || $env->is_multiline) {
                    // Strip outer quotes from real_value and apply proper bash escaping
                    $value = trim($env->real_value, "'");
                    $escapedValue = escapeBashEnvValue($value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: literal/multiline');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   raw real_value: {$env->real_value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   stripped value: {$value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                } else {
                    // For normal vars, use double quotes to allow $VAR expansion
                    $escapedValue = escapeBashDoubleQuoted($env->real_value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: normal (allows expansion)');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   real_value: {$env->real_value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                }
            }
        } else {
            $sorted_environment_variables = $this->application->environment_variables_preview()
                ->where('is_buildtime', true)  // ONLY build-time variables
                ->orderBy($this->application->settings->is_env_sorting_enabled ? 'key' : 'id')
                ->get();

            // For Docker Compose, filter out SERVICE_FQDN and SERVICE_URL as we generate these with PR-specific values
            if ($this->build_pack === 'dockercompose') {
                $sorted_environment_variables = $sorted_environment_variables->filter(function ($env) {
                    return ! str($env->key)->startsWith('SERVICE_FQDN_') && ! str($env->key)->startsWith('SERVICE_URL_');
                });
            }

            foreach ($sorted_environment_variables as $env) {
                // For literal/multiline vars, real_value includes quotes that we need to remove
                if ($env->is_literal || $env->is_multiline) {
                    // Strip outer quotes from real_value and apply proper bash escaping
                    $value = trim($env->real_value, "'");
                    $escapedValue = escapeBashEnvValue($value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: literal/multiline');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   raw real_value: {$env->real_value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   stripped value: {$value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                } else {
                    // For normal vars, use double quotes to allow $VAR expansion
                    $escapedValue = escapeBashDoubleQuoted($env->real_value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: normal (allows expansion)');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   real_value: {$env->real_value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                }
            }
        }

        // Convert dictionary back to collection in KEY=VALUE format
        $envs = collect([]);
        foreach ($envs_dict as $key => $value) {
            $envs->push($key.'='.$value);
        }

        // Return the generated environment variables
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry("[DEBUG] Total build-time env variables: {$envs->count()}");
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
        }

        return $envs;
    }

    private function save_buildtime_environment_variables()
    {
        // Generate build-time environment variables locally
        $environment_variables = $this->generate_buildtime_environment_variables();

        // Save .env file for build phase in /artifacts to prevent it from being copied into Docker images
        if ($environment_variables->isNotEmpty()) {
            $envs_base64 = base64_encode($environment_variables->implode("\n"));

            $this->application_deployment_queue->addLogEntry('Creating build-time .env file in /artifacts (outside Docker context).', hidden: true);

            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d | tee ".self::BUILD_TIME_ENV_PATH.' > /dev/null'),
                ],
                [
                    executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_TIME_ENV_PATH),
                    'hidden' => true,
                ],
            );
        } elseif ($this->build_pack === 'dockercompose' || $this->build_pack === 'dockerfile') {
            // For Docker Compose and Dockerfile, create an empty .env file even if there are no build-time variables
            // This ensures the file exists when referenced in build commands
            $this->application_deployment_queue->addLogEntry('Creating empty build-time .env file in /artifacts (no build-time variables defined).', hidden: true);

            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, 'touch '.self::BUILD_TIME_ENV_PATH),
                ]
            );
        }
    }

    private function elixir_finetunes()
    {
        if ($this->pull_request_id === 0) {
            $envType = 'environment_variables';
        } else {
            $envType = 'environment_variables_preview';
        }
        $mix_env = $this->application->{$envType}->where('key', 'MIX_ENV')->first();
        if (! $mix_env) {
            $this->application_deployment_queue->addLogEntry('MIX_ENV environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add MIX_ENV environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
        $secret_key_base = $this->application->{$envType}->where('key', 'SECRET_KEY_BASE')->first();
        if (! $secret_key_base) {
            $this->application_deployment_queue->addLogEntry('SECRET_KEY_BASE environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add SECRET_KEY_BASE environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
        $database_url = $this->application->{$envType}->where('key', 'DATABASE_URL')->first();
        if (! $database_url) {
            $this->application_deployment_queue->addLogEntry('DATABASE_URL environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add DATABASE_URL environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
    }

    private function laravel_finetunes()
    {
        if ($this->pull_request_id === 0) {
            $envType = 'environment_variables';
        } else {
            $envType = 'environment_variables_preview';
        }
        $nixpacks_php_fallback_path = $this->application->{$envType}->where('key', 'NIXPACKS_PHP_FALLBACK_PATH')->first();
        $nixpacks_php_root_dir = $this->application->{$envType}->where('key', 'NIXPACKS_PHP_ROOT_DIR')->first();

        if (! $nixpacks_php_fallback_path) {
            $nixpacks_php_fallback_path = new EnvironmentVariable;
            $nixpacks_php_fallback_path->key = 'NIXPACKS_PHP_FALLBACK_PATH';
            $nixpacks_php_fallback_path->value = '/index.php';
            $nixpacks_php_fallback_path->resourceable_id = $this->application->id;
            $nixpacks_php_fallback_path->resourceable_type = 'App\Models\Application';
            $nixpacks_php_fallback_path->save();
        }
        if (! $nixpacks_php_root_dir) {
            $nixpacks_php_root_dir = new EnvironmentVariable;
            $nixpacks_php_root_dir->key = 'NIXPACKS_PHP_ROOT_DIR';
            $nixpacks_php_root_dir->value = '/app/public';
            $nixpacks_php_root_dir->resourceable_id = $this->application->id;
            $nixpacks_php_root_dir->resourceable_type = 'App\Models\Application';
            $nixpacks_php_root_dir->save();
        }

        return [$nixpacks_php_fallback_path, $nixpacks_php_root_dir];
    }

    private function rolling_update()
    {
        try {
            $this->checkForCancellation();
            if ($this->server->isSwarm()) {
                $this->application_deployment_queue->addLogEntry('Rolling update started.');
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "docker stack deploy --detach=true --with-registry-auth -c {$this->workdir}{$this->docker_compose_location} {$this->application->uuid}"),
                    ],
                );
                $this->application_deployment_queue->addLogEntry('Rolling update completed.');
            } else {
                if ($this->use_build_server) {
                    $this->write_deployment_configurations();
                    $this->server = $this->original_server;
                }
                if (count($this->application->ports_mappings_array) > 0 || (bool) $this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty() || $this->pull_request_id !== 0 || str($this->application->custom_docker_run_options)->contains('--ip') || str($this->application->custom_docker_run_options)->contains('--ip6')) {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    if (count($this->application->ports_mappings_array) > 0) {
                        $this->application_deployment_queue->addLogEntry('Application has ports mapped to the host system, rolling update is not supported.');
                    }
                    if ((bool) $this->application->settings->is_consistent_container_name_enabled) {
                        $this->application_deployment_queue->addLogEntry('Consistent container name feature enabled, rolling update is not supported.');
                    }
                    if (str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                        $this->application_deployment_queue->addLogEntry('Custom internal name is set, rolling update is not supported.');
                    }
                    if ($this->pull_request_id !== 0) {
                        $this->application->settings->is_consistent_container_name_enabled = true;
                        $this->application_deployment_queue->addLogEntry('Pull request deployment, rolling update is not supported.');
                    }
                    if (str($this->application->custom_docker_run_options)->contains('--ip') || str($this->application->custom_docker_run_options)->contains('--ip6')) {
                        $this->application_deployment_queue->addLogEntry('Custom IP address is set, rolling update is not supported.');
                    }
                    $this->stop_running_container(force: true);
                    $this->start_by_compose_file();
                } else {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    $this->application_deployment_queue->addLogEntry('Rolling update started.');
                    $this->start_by_compose_file();
                    $this->health_check();
                    $this->stop_running_container();
                    $this->application_deployment_queue->addLogEntry('Rolling update completed.');
                }
            }
        } catch (Exception $e) {
            throw new DeploymentException('Rolling update failed ('.get_class($e).'): '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    private function health_check()
    {
        try {
            if ($this->server->isSwarm()) {
                // Implement healthcheck for swarm
            } else {
                if ($this->application->isHealthcheckDisabled() && $this->application->custom_healthcheck_found === false) {
                    $this->newVersionIsHealthy = true;

                    return;
                }
                if ($this->application->custom_healthcheck_found) {
                    $this->application_deployment_queue->addLogEntry('Custom healthcheck found in Dockerfile.');
                }
                if ($this->container_name) {
                    $counter = 1;
                    $this->application_deployment_queue->addLogEntry('Waiting for healthcheck to pass on the new container.');
                    if ($this->full_healthcheck_url && ! $this->application->custom_healthcheck_found) {
                        $this->application_deployment_queue->addLogEntry("Healthcheck URL (inside the container): {$this->full_healthcheck_url}");
                    }
                    $this->application_deployment_queue->addLogEntry("Waiting for the start period ({$this->application->health_check_start_period} seconds) before starting healthcheck.");
                    $sleeptime = 0;
                    while ($sleeptime < $this->application->health_check_start_period) {
                        Sleep::for(1)->seconds();
                        $sleeptime++;
                    }
                    while ($counter <= $this->application->health_check_retries) {
                        $this->execute_remote_command(
                            [
                                "docker inspect --format='{{json .State.Health.Status}}' {$this->container_name}",
                                'hidden' => true,
                                'save' => 'health_check',
                                'append' => false,
                            ],
                            [
                                "docker inspect --format='{{json .State.Health.Log}}' {$this->container_name}",
                                'hidden' => true,
                                'save' => 'health_check_logs',
                                'append' => false,
                            ],
                        );
                        $this->application_deployment_queue->addLogEntry("Attempt {$counter} of {$this->application->health_check_retries} | Healthcheck status: {$this->saved_outputs->get('health_check')}");
                        $health_check_logs = data_get(collect(json_decode($this->saved_outputs->get('health_check_logs')))->last(), 'Output', '(no logs)');
                        if (empty($health_check_logs)) {
                            $health_check_logs = '(no logs)';
                        }
                        $health_check_return_code = data_get(collect(json_decode($this->saved_outputs->get('health_check_logs')))->last(), 'ExitCode', '(no return code)');
                        if ($health_check_logs !== '(no logs)' || $health_check_return_code !== '(no return code)') {
                            $this->application_deployment_queue->addLogEntry("Healthcheck logs: {$health_check_logs} | Return code: {$health_check_return_code}");
                        }

                        if (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'healthy') {
                            $this->newVersionIsHealthy = true;
                            $this->application->update(['status' => 'running']);
                            $this->application_deployment_queue->addLogEntry('New container is healthy.');
                            break;
                        } elseif (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'unhealthy') {
                            $this->newVersionIsHealthy = false;
                            $this->application_deployment_queue->addLogEntry('New container is unhealthy.', type: 'error');
                            $this->query_logs();
                            break;
                        }
                        $counter++;
                        $sleeptime = 0;
                        while ($sleeptime < $this->application->health_check_interval) {
                            Sleep::for(1)->seconds();
                            $sleeptime++;
                        }
                    }
                    if (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'starting') {
                        $this->query_logs();
                    }
                }
            }
        } catch (Exception $e) {
            throw new DeploymentException('Health check failed ('.get_class($e).'): '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    private function query_logs()
    {
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Container logs:');
        $this->execute_remote_command(
            [
                'command' => "docker logs -n 100 {$this->container_name}",
                'type' => 'stderr',
                'ignore_errors' => true,
            ],
        );
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
    }

    private function deploy_pull_request()
    {
        if ($this->application->build_pack === 'dockercompose') {
            $this->deploy_docker_compose_buildpack();

            return;
        }
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->newVersionIsHealthy = true;
        $this->generate_image_names();
        $this->application_deployment_queue->addLogEntry("Starting pull request (#{$this->pull_request_id}) deployment of {$this->customRepository}:{$this->application->git_branch}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->clone_repository();
        $this->cleanup_git();
        if ($this->application->build_pack === 'nixpacks') {
            $this->generate_nixpacks_confs();
        }
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        if ($this->application->build_pack === 'dockerfile') {
            $this->add_build_env_variables_to_dockerfile();
        }
        $this->build_image();

        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();
        $this->push_to_docker_registry();
        $this->rolling_update();
    }

    private function create_workdir()
    {
        if ($this->use_build_server) {
            $this->server = $this->original_server;
            $this->execute_remote_command(
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
            $this->server = $this->build_server;
            $this->execute_remote_command(
                [
                    'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->workdir}"),
                ],
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
        } else {
            $this->execute_remote_command(
                [
                    'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->workdir}"),
                ],
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
        }
    }

    private function prepare_builder_image(bool $firstTry = true)
    {
        $this->checkForCancellation();
        $helperImage = config('constants.coolify.helper_image');
        $helperImage = "{$helperImage}:".getHelperVersion();
        // Get user home directory
        $this->serverUserHomeDir = instant_remote_process(['echo $HOME'], $this->server);
        $this->dockerConfigFileExists = instant_remote_process(["test -f {$this->serverUserHomeDir}/.docker/config.json && echo 'OK' || echo 'NOK'"], $this->server);

        $env_flags = $this->generate_docker_env_flags_for_secrets();
        if ($this->use_build_server) {
            if ($this->dockerConfigFileExists === 'NOK') {
                throw new DeploymentException('Docker config file (~/.docker/config.json) not found on the build server. Please run "docker login" to login to the docker registry on the server.');
            }
            $runCommand = "docker run -d --name {$this->deployment_uuid} {$env_flags} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($this->dockerConfigFileExists === 'OK') {
                $runCommand = "docker run -d --network {$this->destination->network} --name {$this->deployment_uuid} {$env_flags} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $runCommand = "docker run -d --network {$this->destination->network} --name {$this->deployment_uuid} {$env_flags} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }
        if ($firstTry) {
            $this->application_deployment_queue->addLogEntry("Preparing container with helper image: $helperImage");
        } else {
            $this->application_deployment_queue->addLogEntry('Preparing container with helper image with updated envs.');
        }

        $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);
        $this->execute_remote_command(
            [
                $runCommand,
                'hidden' => true,
            ],
            [
                'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->basedir}"),
            ],
        );
        $this->run_pre_deployment_command();
    }

    private function restart_builder_container_with_actual_commit()
    {
        // Stop the current helper container (no need for rm -f as it was started with --rm)
        $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);

        // Clear cached env_args to force regeneration with actual SOURCE_COMMIT value
        $this->env_args = null;

        // Restart the helper container with updated environment variables (including actual SOURCE_COMMIT)
        $this->prepare_builder_image(firstTry: false);
    }

    private function deploy_to_additional_destinations()
    {
        if ($this->application->additional_networks->count() === 0) {
            return;
        }
        if ($this->pull_request_id !== 0) {
            return;
        }
        $destination_ids = $this->application->additional_networks->pluck('id');
        if ($this->server->isSwarm()) {
            $this->application_deployment_queue->addLogEntry('Additional destinations are not supported in swarm mode.');

            return;
        }
        if ($destination_ids->contains($this->destination->id)) {
            return;
        }
        foreach ($destination_ids as $destination_id) {
            $destination = StandaloneDocker::find($destination_id);
            if (! $destination) {
                continue;
            }
            $server = $destination->server;
            if ($server->team_id !== $this->mainServer->team_id) {
                $this->application_deployment_queue->addLogEntry("Skipping deployment to {$server->name}. Not in the same team?!");

                continue;
            }
            $deployment_uuid = new Cuid2;
            queue_application_deployment(
                deployment_uuid: $deployment_uuid,
                application: $this->application,
                server: $server,
                destination: $destination,
                no_questions_asked: true,
            );
            $this->application_deployment_queue->addLogEntry("Deployment to {$server->name}. Logs: ".route('project.application.deployment.show', [
                'project_uuid' => data_get($this->application, 'environment.project.uuid'),
                'application_uuid' => data_get($this->application, 'uuid'),
                'deployment_uuid' => $deployment_uuid,
                'environment_uuid' => data_get($this->application, 'environment.uuid'),
            ]));
        }
    }

    private function set_coolify_variables()
    {
        $this->coolify_variables = '';

        // Only include SOURCE_COMMIT in build context if enabled in settings
        if ($this->application->settings->include_source_commit_in_build) {
            $this->coolify_variables .= "SOURCE_COMMIT={$this->commit} ";
        }
        if ($this->pull_request_id === 0) {
            $fqdn = $this->application->fqdn;
        } else {
            $fqdn = $this->preview->fqdn;
        }
        if (isset($fqdn)) {
            $url = Url::fromString($fqdn);
            $fqdn = $url->getHost();
            $url = $url->withHost($fqdn)->withPort(null)->__toString();
            if ((int) $this->application->compose_parsing_version >= 3) {
                $this->coolify_variables .= "COOLIFY_URL={$url} ";
                $this->coolify_variables .= "COOLIFY_FQDN={$fqdn} ";
            } else {
                $this->coolify_variables .= "COOLIFY_URL={$fqdn} ";
                $this->coolify_variables .= "COOLIFY_FQDN={$url} ";
            }
        }
        if (isset($this->application->git_branch)) {
            $this->coolify_variables .= "COOLIFY_BRANCH={$this->application->git_branch} ";
        }
        $this->coolify_variables .= "COOLIFY_RESOURCE_UUID={$this->application->uuid} ";
    }

    private function check_git_if_build_needed()
    {
        if (is_object($this->source) && $this->source->getMorphClass() === \App\Models\GithubApp::class && $this->source->is_public === false) {
            $repository = githubApi($this->source, "repos/{$this->customRepository}");
            $data = data_get($repository, 'data');
            $repository_project_id = data_get($data, 'id');
            if (isset($repository_project_id)) {
                if (blank($this->application->repository_project_id) || $this->application->repository_project_id !== $repository_project_id) {
                    $this->application->repository_project_id = $repository_project_id;
                    $this->application->save();
                }
            }
        }
        $this->generate_git_import_commands();
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            $local_branch = "pull/{$this->pull_request_id}/head";
        }
        // Build an exact refspec for ls-remote so we don't match similarly named branches (e.g., changeset-release/main)
        if ($this->pull_request_id === 0) {
            $lsRemoteRef = "refs/heads/{$local_branch}";
        } else {
            if ($this->git_type === 'github' || $this->git_type === 'gitea') {
                $lsRemoteRef = "refs/pull/{$this->pull_request_id}/head";
            } elseif ($this->git_type === 'gitlab') {
                $lsRemoteRef = "refs/merge-requests/{$this->pull_request_id}/head";
            } else {
                // Fallback to the original value if provider-specific ref is unknown
                $lsRemoteRef = $local_branch;
            }
        }
        $private_key = data_get($this->application, 'private_key.private_key');
        if ($private_key) {
            $private_key = base64_encode($private_key);
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, 'mkdir -p /root/.ssh'),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$private_key}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, 'chmod 600 /root/.ssh/id_rsa'),
                ],
                [
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$this->fullRepoUrl} {$lsRemoteRef}"),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ]
            );
        } else {
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$this->fullRepoUrl} {$lsRemoteRef}"),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ],
            );
        }
        if ($this->saved_outputs->get('git_commit_sha') && ! $this->rollback) {
            // Extract commit SHA from git ls-remote output, handling multi-line output (e.g., redirect warnings)
            // Expected format: "commit_sha\trefs/heads/branch" possibly preceded by warning lines
            // Note: Git warnings can be on the same line as the result (no newline)
            $lsRemoteOutput = $this->saved_outputs->get('git_commit_sha');

            // Find the part containing a tab (the actual ls-remote result)
            // Handle cases where warning is on the same line as the result
            if ($lsRemoteOutput->contains("\t")) {
                // Get everything from the last occurrence of a valid commit SHA pattern before the tab
                // A valid commit SHA is 40 hex characters
                $output = $lsRemoteOutput->value();

                // Extract the line with the tab (actual ls-remote result)
                preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);

                if (isset($matches[1])) {
                    $this->commit = $matches[1];
                    $this->application_deployment_queue->commit = $this->commit;
                    $this->application_deployment_queue->save();
                }
            }
        }
        $this->set_coolify_variables();

        // Restart helper container with actual SOURCE_COMMIT value
        if ($this->application->settings->use_build_secrets && $this->commit !== 'HEAD') {
            $this->application_deployment_queue->addLogEntry('Restarting helper container with actual SOURCE_COMMIT value.');
            $this->restart_builder_container_with_actual_commit();
        }
    }

    private function clone_repository()
    {
        $importCommands = $this->generate_git_import_commands();
        $this->application_deployment_queue->addLogEntry("\n----------------------------------------");
        $this->application_deployment_queue->addLogEntry("Importing {$this->customRepository}:{$this->application->git_branch} (commit sha {$this->commit}) to {$this->basedir}.");
        if ($this->pull_request_id !== 0) {
            $this->application_deployment_queue->addLogEntry("Checking out tag pull/{$this->pull_request_id}/head.");
        }
        $this->execute_remote_command(
            [
                $importCommands,
                'hidden' => true,
            ]
        );
        $this->create_workdir();
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "cd {$this->workdir} && git log -1 {$this->commit} --pretty=%B"),
                'hidden' => true,
                'save' => 'commit_message',
            ]
        );
        if ($this->saved_outputs->get('commit_message')) {
            $commit_message = str($this->saved_outputs->get('commit_message'));
            $this->application_deployment_queue->commit_message = $commit_message->value();
            ApplicationDeploymentQueue::whereCommit($this->commit)->whereApplicationId($this->application->id)->update(
                ['commit_message' => $commit_message->value()]
            );
        }
    }

    private function generate_git_import_commands()
    {
        ['commands' => $commands, 'branch' => $this->branch, 'fullRepoUrl' => $this->fullRepoUrl] = $this->application->generateGitImportCommands(
            deployment_uuid: $this->deployment_uuid,
            pull_request_id: $this->pull_request_id,
            git_type: $this->git_type,
            commit: $this->commit
        );

        return $commands;
    }

    private function cleanup_git()
    {
        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, "rm -fr {$this->basedir}/.git")],
        );
    }

    private function generate_nixpacks_confs()
    {
        $nixpacks_command = $this->nixpacks_build_cmd();
        $this->application_deployment_queue->addLogEntry("Generating nixpacks configuration with: $nixpacks_command");

        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, $nixpacks_command), 'save' => 'nixpacks_plan', 'hidden' => true],
            [executeInDocker($this->deployment_uuid, "nixpacks detect {$this->workdir}"), 'save' => 'nixpacks_type', 'hidden' => true],
        );
        if ($this->saved_outputs->get('nixpacks_type')) {
            $this->nixpacks_type = $this->saved_outputs->get('nixpacks_type');
            if (str($this->nixpacks_type)->isEmpty()) {
                throw new DeploymentException('Nixpacks failed to detect the application type. Please check the documentation of Nixpacks: https://nixpacks.com/docs/providers');
            }
        }

        if ($this->saved_outputs->get('nixpacks_plan')) {
            $this->nixpacks_plan = $this->saved_outputs->get('nixpacks_plan');
            if ($this->nixpacks_plan) {
                $this->application_deployment_queue->addLogEntry("Found application type: {$this->nixpacks_type}.");
                $this->application_deployment_queue->addLogEntry("If you need further customization, please check the documentation of Nixpacks: https://nixpacks.com/docs/providers/{$this->nixpacks_type}");
                $parsed = json_decode($this->nixpacks_plan, true);

                // Do any modifications here
                // We need to generate envs here because nixpacks need to know to generate a proper Dockerfile
                $this->generate_env_variables();
                $merged_envs = collect(data_get($parsed, 'variables', []))->merge($this->env_args);
                $aptPkgs = data_get($parsed, 'phases.setup.aptPkgs', []);
                if (count($aptPkgs) === 0) {
                    $aptPkgs = ['curl', 'wget'];
                    data_set($parsed, 'phases.setup.aptPkgs', ['curl', 'wget']);
                } else {
                    if (! in_array('curl', $aptPkgs)) {
                        $aptPkgs[] = 'curl';
                    }
                    if (! in_array('wget', $aptPkgs)) {
                        $aptPkgs[] = 'wget';
                    }
                    data_set($parsed, 'phases.setup.aptPkgs', $aptPkgs);
                }
                data_set($parsed, 'variables', $merged_envs->toArray());
                $is_laravel = data_get($parsed, 'variables.IS_LARAVEL', false);
                if ($is_laravel) {
                    $variables = $this->laravel_finetunes();
                    data_set($parsed, 'variables.NIXPACKS_PHP_FALLBACK_PATH', $variables[0]->value);
                    data_set($parsed, 'variables.NIXPACKS_PHP_ROOT_DIR', $variables[1]->value);
                }
                if ($this->nixpacks_type === 'elixir') {
                    $this->elixir_finetunes();
                }
                if ($this->nixpacks_type === 'node') {
                    // Check if NIXPACKS_NODE_VERSION is set
                    $variables = data_get($parsed, 'variables', []);
                    if (! isset($variables['NIXPACKS_NODE_VERSION'])) {
                        $this->application_deployment_queue->addLogEntry('----------------------------------------');
                        $this->application_deployment_queue->addLogEntry('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.');
                        $this->application_deployment_queue->addLogEntry('You can override this by setting NIXPACKS_NODE_VERSION=22 in your environment variables.');
                    }
                }
                $this->nixpacks_plan = json_encode($parsed, JSON_PRETTY_PRINT);
                $this->nixpacks_plan_json = collect($parsed);
                $this->application_deployment_queue->addLogEntry("Final Nixpacks plan: {$this->nixpacks_plan}", hidden: true);
                if ($this->nixpacks_type === 'rust') {
                    // temporary: disable healthcheck for rust because the start phase does not have curl/wget
                    $this->application->health_check_enabled = false;
                    $this->application->save();
                }
            }
        }
    }

    private function nixpacks_build_cmd()
    {
        $this->generate_nixpacks_env_variables();
        $nixpacks_command = "nixpacks plan -f json {$this->env_nixpacks_args}";
        if ($this->application->build_command) {
            $nixpacks_command .= " --build-cmd \"{$this->application->build_command}\"";
        }
        if ($this->application->start_command) {
            $nixpacks_command .= " --start-cmd \"{$this->application->start_command}\"";
        }
        if ($this->application->install_command) {
            $nixpacks_command .= " --install-cmd \"{$this->application->install_command}\"";
        }
        $nixpacks_command .= " {$this->workdir}";

        return $nixpacks_command;
    }

    private function generate_nixpacks_env_variables()
    {
        $this->env_nixpacks_args = collect([]);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->nixpacks_environment_variables as $env) {
                if (! is_null($env->real_value) && $env->real_value !== '') {
                    $this->env_nixpacks_args->push("--env {$env->key}={$env->real_value}");
                }
            }
        } else {
            foreach ($this->application->nixpacks_environment_variables_preview as $env) {
                if (! is_null($env->real_value) && $env->real_value !== '') {
                    $this->env_nixpacks_args->push("--env {$env->key}={$env->real_value}");
                }
            }
        }

        // Add COOLIFY_* environment variables to Nixpacks build context
        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        $coolify_envs->each(function ($value, $key) {
            // Only add environment variables with non-null and non-empty values
            if (! is_null($value) && $value !== '') {
                $this->env_nixpacks_args->push("--env {$key}={$value}");
            }
        });

        $this->env_nixpacks_args = $this->env_nixpacks_args->implode(' ');
    }

    private function generate_coolify_env_variables(bool $forBuildTime = false): Collection
    {
        $coolify_envs = collect([]);
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            // Only add SOURCE_COMMIT for runtime OR when explicitly enabled for build-time
            // SOURCE_COMMIT changes with each commit and breaks Docker cache if included in build
            if (! $forBuildTime || $this->application->settings->include_source_commit_in_build) {
                if ($this->application->environment_variables_preview->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                    if (! is_null($this->commit)) {
                        $coolify_envs->put('SOURCE_COMMIT', $this->commit);
                    } else {
                        $coolify_envs->put('SOURCE_COMMIT', 'unknown');
                    }
                }
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_URL', $this->preview->fqdn);
                } else {
                    $coolify_envs->put('COOLIFY_FQDN', $this->preview->fqdn);
                }
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->preview->fqdn)->replace('http://', '')->replace('https://', '');
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_FQDN', $url);
                } else {
                    $coolify_envs->put('COOLIFY_URL', $url);
                }
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_BRANCH', $local_branch);
                }
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_RESOURCE_UUID', $this->application->uuid);
                }
                // Only add COOLIFY_CONTAINER_NAME for runtime (not build-time) - it changes every deployment and breaks Docker cache
                if (! $forBuildTime) {
                    if ($this->application->environment_variables_preview->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                        $coolify_envs->put('COOLIFY_CONTAINER_NAME', $this->container_name);
                    }
                }
            }

            add_coolify_default_environment_variables($this->application, $coolify_envs, $this->application->environment_variables_preview);

        } else {
            // Only add SOURCE_COMMIT for runtime OR when explicitly enabled for build-time
            // SOURCE_COMMIT changes with each commit and breaks Docker cache if included in build
            if (! $forBuildTime || $this->application->settings->include_source_commit_in_build) {
                if ($this->application->environment_variables->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                    if (! is_null($this->commit)) {
                        $coolify_envs->put('SOURCE_COMMIT', $this->commit);
                    } else {
                        $coolify_envs->put('SOURCE_COMMIT', 'unknown');
                    }
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_URL', $this->application->fqdn);
                } else {
                    $coolify_envs->put('COOLIFY_FQDN', $this->application->fqdn);
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->application->fqdn)->replace('http://', '')->replace('https://', '');
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_FQDN', $url);
                } else {
                    $coolify_envs->put('COOLIFY_URL', $url);
                }
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_BRANCH', $local_branch);
                }
                if ($this->application->environment_variables->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_RESOURCE_UUID', $this->application->uuid);
                }
                // Only add COOLIFY_CONTAINER_NAME for runtime (not build-time) - it changes every deployment and breaks Docker cache
                if (! $forBuildTime) {
                    if ($this->application->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                        $coolify_envs->put('COOLIFY_CONTAINER_NAME', $this->container_name);
                    }
                }
            }

            add_coolify_default_environment_variables($this->application, $coolify_envs, $this->application->environment_variables);

        }

        return $coolify_envs;
    }

    private function generate_env_variables()
    {
        $this->env_args = collect([]);

        // Only include SOURCE_COMMIT in build args if enabled in settings
        if ($this->application->settings->include_source_commit_in_build) {
            $this->env_args->put('SOURCE_COMMIT', $this->commit);
        }

        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        $coolify_envs->each(function ($value, $key) {
            $this->env_args->put($key, $value);
        });

        // For build process, include only environment variables where is_buildtime = true
        if ($this->pull_request_id === 0) {
            $envs = $this->application->environment_variables()
                ->where('key', 'not like', 'NIXPACKS_%')
                ->where('is_buildtime', true)
                ->get();

            foreach ($envs as $env) {
                if (! is_null($env->real_value)) {
                    $this->env_args->put($env->key, $env->real_value);
                }
            }
        } else {
            $envs = $this->application->environment_variables_preview()
                ->where('key', 'not like', 'NIXPACKS_%')
                ->where('is_buildtime', true)
                ->get();

            foreach ($envs as $env) {
                if (! is_null($env->real_value)) {
                    $this->env_args->put($env->key, $env->real_value);
                }
            }
        }
    }

    private function generate_compose_file()
    {
        $this->checkForCancellation();
        $this->create_workdir();
        $ports = $this->application->main_port();
        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->application->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        if (data_get($this->application, 'custom_labels')) {
            $this->application->parseContainerLabels();
            $labels = collect(preg_split("/\r\n|\n|\r/", base64_decode($this->application->custom_labels)));
            $labels = $labels->filter(function ($value, $key) {
                return ! Str::startsWith($value, 'coolify.');
            });
            $this->application->custom_labels = base64_encode($labels->implode("\n"));
            $this->application->save();
        } else {
            if ($this->application->settings->is_container_label_readonly_enabled) {
                $labels = collect(generateLabelsApplication($this->application, $this->preview));
            }
        }
        if ($this->pull_request_id !== 0) {
            $labels = collect(generateLabelsApplication($this->application, $this->preview));
        }
        if ($this->application->settings->is_container_label_escape_enabled) {
            $labels = $labels->map(function ($value, $key) {
                return escapeDollarSign($value);
            });
        }
        $labels = $labels->merge(defaultLabels($this->application->id, $this->application->uuid, $this->application->project()->name, $this->application->name, $this->application->environment->name, $this->pull_request_id))->toArray();

        // Check for custom HEALTHCHECK
        if ($this->application->build_pack === 'dockerfile' || $this->application->dockerfile) {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
                'hidden' => true,
                'save' => 'dockerfile_from_repo',
                'ignore_errors' => true,
            ]);
            $this->application->parseHealthcheckFromDockerfile($this->saved_outputs->get('dockerfile_from_repo'));
        }
        $custom_network_aliases = [];
        if (! empty($this->application->custom_network_aliases_array)) {
            $custom_network_aliases = $this->application->custom_network_aliases_array;
        }
        $docker_compose = [
            'services' => [
                $this->container_name => [
                    'image' => $this->production_image_name,
                    'container_name' => $this->container_name,
                    'restart' => RESTART_MODE,
                    'expose' => $ports,
                    'networks' => [
                        $this->destination->network => [
                            'aliases' => array_merge(
                                [$this->container_name],
                                $custom_network_aliases
                            ),
                        ],
                    ],
                    'mem_limit' => $this->application->limits_memory,
                    'memswap_limit' => $this->application->limits_memory_swap,
                    'mem_swappiness' => $this->application->limits_memory_swappiness,
                    'mem_reservation' => $this->application->limits_memory_reservation,
                    'cpus' => (float) $this->application->limits_cpus,
                    'cpu_shares' => $this->application->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->destination->network => [
                    'external' => true,
                    'name' => $this->destination->network,
                    'attachable' => true,
                ],
            ],
        ];
        // Always use .env file
        $docker_compose['services'][$this->container_name]['env_file'] = ['.env'];

        // Only add Coolify healthcheck if no custom HEALTHCHECK found in Dockerfile
        // If custom_healthcheck_found is true, the Dockerfile's HEALTHCHECK will be used
        // If healthcheck is disabled, no healthcheck will be added
        if (! $this->application->custom_healthcheck_found && ! $this->application->isHealthcheckDisabled()) {
            $docker_compose['services'][$this->container_name]['healthcheck'] = [
                'test' => [
                    'CMD-SHELL',
                    $this->generate_healthcheck_commands(),
                ],
                'interval' => $this->application->health_check_interval.'s',
                'timeout' => $this->application->health_check_timeout.'s',
                'retries' => $this->application->health_check_retries,
                'start_period' => $this->application->health_check_start_period.'s',
            ];
        }

        if (! is_null($this->application->limits_cpuset)) {
            data_set($docker_compose, 'services.'.$this->container_name.'.cpuset', $this->application->limits_cpuset);
        }
        if ($this->server->isSwarm()) {
            data_forget($docker_compose, 'services.'.$this->container_name.'.container_name');
            data_forget($docker_compose, 'services.'.$this->container_name.'.expose');
            data_forget($docker_compose, 'services.'.$this->container_name.'.restart');

            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_limit');
            data_forget($docker_compose, 'services.'.$this->container_name.'.memswap_limit');
            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_swappiness');
            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_reservation');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpus');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpuset');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpu_shares');

            $docker_compose['services'][$this->container_name]['deploy'] = [
                'mode' => 'replicated',
                'replicas' => data_get($this->application, 'swarm_replicas', 1),
                'update_config' => [
                    'order' => 'start-first',
                ],
                'rollback_config' => [
                    'order' => 'start-first',
                ],
                'labels' => $labels,
                'resources' => [
                    'limits' => [
                        'cpus' => $this->application->limits_cpus,
                        'memory' => $this->application->limits_memory,
                    ],
                    'reservations' => [
                        'cpus' => $this->application->limits_cpus,
                        'memory' => $this->application->limits_memory,
                    ],
                ],
            ];
            if (data_get($this->application, 'swarm_placement_constraints')) {
                $swarm_placement_constraints = Yaml::parse(base64_decode(data_get($this->application, 'swarm_placement_constraints')));
                $docker_compose['services'][$this->container_name]['deploy'] = array_merge(
                    $docker_compose['services'][$this->container_name]['deploy'],
                    $swarm_placement_constraints
                );
            }
            if (data_get($this->application, 'settings.is_swarm_only_worker_nodes')) {
                $docker_compose['services'][$this->container_name]['deploy']['placement']['constraints'][] = 'node.role == worker';
            }
            if ($this->pull_request_id !== 0) {
                $docker_compose['services'][$this->container_name]['deploy']['replicas'] = 1;
            }
        } else {
            $docker_compose['services'][$this->container_name]['labels'] = $labels;
        }
        if ($this->server->isLogDrainEnabled() && $this->application->isLogDrainEnabled()) {
            $docker_compose['services'][$this->container_name]['logging'] = generate_fluentd_configuration();
        }
        if ($this->application->settings->is_gpu_enabled) {
            $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'] = [
                [
                    'driver' => data_get($this->application, 'settings.gpu_driver', 'nvidia'),
                    'capabilities' => ['gpu'],
                    'options' => data_get($this->application, 'settings.gpu_options', []),
                ],
            ];
            if (data_get($this->application, 'settings.gpu_count')) {
                $count = data_get($this->application, 'settings.gpu_count');
                if ($count === 'all') {
                    $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = $count;
                } else {
                    $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = (int) $count;
                }
            } elseif (data_get($this->application, 'settings.gpu_device_ids')) {
                $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['ids'] = data_get($this->application, 'settings.gpu_device_ids');
            }
        }
        if ($this->application->isHealthcheckDisabled()) {
            data_forget($docker_compose, 'services.'.$this->container_name.'.healthcheck');
        }
        if (count($this->application->ports_mappings_array) > 0 && $this->pull_request_id === 0) {
            $docker_compose['services'][$this->container_name]['ports'] = $this->application->ports_mappings_array;
        }

        if (count($persistent_storages) > 0) {
            if (! data_get($docker_compose, 'services.'.$this->container_name.'.volumes')) {
                $docker_compose['services'][$this->container_name]['volumes'] = [];
            }
            $docker_compose['services'][$this->container_name]['volumes'] = array_merge($docker_compose['services'][$this->container_name]['volumes'], $persistent_storages);
        }
        if (count($persistent_file_volumes) > 0) {
            if (! data_get($docker_compose, 'services.'.$this->container_name.'.volumes')) {
                $docker_compose['services'][$this->container_name]['volumes'] = [];
            }
            $docker_compose['services'][$this->container_name]['volumes'] = array_merge($docker_compose['services'][$this->container_name]['volumes'], $persistent_file_volumes->map(function ($item) {
                return "$item->fs_path:$item->mount_path";
            })->toArray());
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        if ($this->pull_request_id === 0) {
            $custom_compose = convertDockerRunToCompose($this->application->custom_docker_run_options);
            if ((bool) $this->application->settings->is_consistent_container_name_enabled) {
                if (! $this->application->settings->custom_internal_name) {
                    $docker_compose['services'][$this->application->uuid] = $docker_compose['services'][$this->container_name];
                    if (count($custom_compose) > 0) {
                        $ipv4 = data_get($custom_compose, 'ip.0');
                        $ipv6 = data_get($custom_compose, 'ip6.0');
                        data_forget($custom_compose, 'ip');
                        data_forget($custom_compose, 'ip6');
                        if ($ipv4 || $ipv6) {
                            data_forget($docker_compose['services'][$this->application->uuid], 'networks');
                        }
                        if ($ipv4) {
                            $docker_compose['services'][$this->application->uuid]['networks'][$this->destination->network]['ipv4_address'] = $ipv4;
                        }
                        if ($ipv6) {
                            $docker_compose['services'][$this->application->uuid]['networks'][$this->destination->network]['ipv6_address'] = $ipv6;
                        }
                        $docker_compose['services'][$this->application->uuid] = array_merge_recursive($docker_compose['services'][$this->application->uuid], $custom_compose);
                    }
                }
            } else {
                if (count($custom_compose) > 0) {
                    $ipv4 = data_get($custom_compose, 'ip.0');
                    $ipv6 = data_get($custom_compose, 'ip6.0');
                    data_forget($custom_compose, 'ip');
                    data_forget($custom_compose, 'ip6');
                    if ($ipv4 || $ipv6) {
                        data_forget($docker_compose['services'][$this->container_name], 'networks');
                    }
                    if ($ipv4) {
                        $docker_compose['services'][$this->container_name]['networks'][$this->destination->network]['ipv4_address'] = $ipv4;
                    }
                    if ($ipv6) {
                        $docker_compose['services'][$this->container_name]['networks'][$this->destination->network]['ipv6_address'] = $ipv6;
                    }
                    $docker_compose['services'][$this->container_name] = array_merge_recursive($docker_compose['services'][$this->container_name], $custom_compose);
                }
            }
        }

        $this->docker_compose = Yaml::dump($docker_compose, 10);
        $this->docker_compose_base64 = base64_encode($this->docker_compose);
        $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}/docker-compose.yaml > /dev/null"), 'hidden' => true]);
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $volume_name = $persistentStorage->host_path;
            } else {
                $volume_name = $persistentStorage->name;
            }
            if ($this->pull_request_id !== 0) {
                $volume_name = addPreviewDeploymentSuffix($volume_name, $this->pull_request_id);
            }
            $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
        }

        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;

            if ($this->pull_request_id !== 0) {
                $name = addPreviewDeploymentSuffix($name, $this->pull_request_id);
            }

            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $local_persistent_volumes_names;
    }

    private function generate_healthcheck_commands()
    {
        if (! $this->application->health_check_port) {
            $health_check_port = $this->application->ports_exposes_array[0];
        } else {
            $health_check_port = $this->application->health_check_port;
        }
        if ($this->application->settings->is_static || $this->application->build_pack === 'static') {
            $health_check_port = 80;
        }
        if ($this->application->health_check_path) {
            $this->full_healthcheck_url = "{$this->application->health_check_method}: {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}{$this->application->health_check_path}";
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}{$this->application->health_check_path} > /dev/null || wget -q -O- {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}{$this->application->health_check_path} > /dev/null || exit 1",
            ];
        } else {
            $this->full_healthcheck_url = "{$this->application->health_check_method}: {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}/";
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}/ > /dev/null || wget -q -O- {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}/ > /dev/null || exit 1",
            ];
        }

        return implode(' ', $generated_healthchecks_commands);
    }

    private function pull_latest_image($image)
    {
        $this->application_deployment_queue->addLogEntry("Pulling latest image ($image) from the registry.");
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "docker pull {$image}"),
                'hidden' => true,
            ]
        );
    }

    private function build_static_image()
    {
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Static deployment. Copying static assets to the image.');
        if ($this->application->static_image) {
            $this->pull_latest_image($this->application->static_image);
        }
        $dockerfile = base64_encode("FROM {$this->application->static_image}
        WORKDIR /usr/share/nginx/html/
        LABEL coolify.deploymentId={$this->deployment_uuid}
        COPY . .
        RUN rm -f /usr/share/nginx/html/nginx.conf
        RUN rm -f /usr/share/nginx/html/Dockerfile
        RUN rm -f /usr/share/nginx/html/docker-compose.yaml
        RUN rm -f /usr/share/nginx/html/.env
        COPY ./nginx.conf /etc/nginx/conf.d/default.conf");
        if (str($this->application->custom_nginx_configuration)->isNotEmpty()) {
            $nginx_config = base64_encode($this->application->custom_nginx_configuration);
        } else {
            if ($this->application->settings->is_spa) {
                $nginx_config = base64_encode(defaultNginxConfiguration('spa'));
            } else {
                $nginx_config = base64_encode(defaultNginxConfiguration());
            }
        }
        $build_command = "docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile --progress plain -t {$this->production_image_name} {$this->workdir}";
        $base64_build_command = base64_encode($build_command);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null"),
            ],
            [
                executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null"),
            ],
            [
                executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ]
        );
        $this->application_deployment_queue->addLogEntry('Building docker image completed.');
    }

    /**
     * Wrap a docker build command with environment export from build-time .env file
     * This enables shell interpolation of variables (e.g., APP_URL=$COOLIFY_URL)
     *
     * @param  string  $build_command  The docker build command to wrap
     * @return string The wrapped command with export statement
     */
    private function wrap_build_command_with_env_export(string $build_command): string
    {
        return "cd {$this->workdir} && set -a && source ".self::BUILD_TIME_ENV_PATH." && set +a && {$build_command}";
    }

    private function build_image()
    {
        // Add Coolify related variables to the build args/secrets
        if ($this->dockerBuildkitSupported) {
            // Coolify variables are already included in the secrets from generate_build_env_variables
            // build_secrets is already a string at this point
        } else {
            // Traditional build args approach - generate COOLIFY_ variables locally
            // Generate COOLIFY_ variables locally for build args
            $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
            $coolify_envs->each(function ($value, $key) {
                $this->build_args->push("--build-arg '{$key}'");
            });
            $this->build_args = $this->build_args instanceof \Illuminate\Support\Collection
                ? $this->build_args->implode(' ')
                : (string) $this->build_args;
        }

        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        if ($this->disableBuildCache) {
            $this->application_deployment_queue->addLogEntry('Docker build cache is disabled. It will not be used during the build process.');
        }
        if ($this->application->build_pack === 'static') {
            $this->application_deployment_queue->addLogEntry('Static deployment. Copying static assets to the image.');
        } else {
            $this->application_deployment_queue->addLogEntry('Building docker image started.');
            $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');
        }

        if ($this->application->settings->is_static) {
            if ($this->application->static_image) {
                $this->pull_latest_image($this->application->static_image);
                $this->application_deployment_queue->addLogEntry('Continuing with the building process.');
            }
            if ($this->application->build_pack === 'nixpacks') {
                $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee ".self::NIXPACKS_PLAN_PATH.' > /dev/null'), 'hidden' => true]);
                if ($this->force_rebuild) {
                    $this->execute_remote_command([
                        executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --no-cache --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                        'hidden' => true,
                    ], [
                        executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                        'hidden' => true,
                    ]);
                    if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                        // Modify the nixpacks Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->build_image_name} {$this->workdir}");
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                        ray($build_command);
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    }
                } else {
                    $this->execute_remote_command([
                        executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                        'hidden' => true,
                    ], [
                        executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                        'hidden' => true,
                    ]);
                    if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                        // Modify the nixpacks Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->build_image_name} {$this->workdir}");
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    }
                }

                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
                $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm '.self::NIXPACKS_PLAN_PATH), 'hidden' => true]);
            } else {
                // Dockerfile buildpack
                if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                    // Modify the Dockerfile to use build secrets
                    $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                    $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->workdir}");
                    }
                } elseif ($this->dockerBuildkitSupported) {
                    // BuildKit without secrets
                    $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                    $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->build_args} {$this->workdir}");
                    }
                } else {
                    // Traditional build with args
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->build_image_name {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->build_image_name {$this->workdir}");
                    }
                }
                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
            }
            $publishDir = trim($this->application->publish_directory, '/');
            $publishDir = $publishDir ? "/{$publishDir}" : '';
            $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from=$this->build_image_name /app{$publishDir} .
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");
            if (str($this->application->custom_nginx_configuration)->isNotEmpty()) {
                $nginx_config = base64_encode($this->application->custom_nginx_configuration);
            } else {
                if ($this->application->settings->is_spa) {
                    $nginx_config = base64_encode(defaultNginxConfiguration('spa'));
                } else {
                    $nginx_config = base64_encode(defaultNginxConfiguration());
                }
            }
            $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
            $base64_build_command = base64_encode($build_command);
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                    'hidden' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                    'hidden' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                    'hidden' => true,
                ]
            );
        } else {
            // Pure Dockerfile based deployment
            if ($this->application->dockerfile) {
                if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                    // Modify the Dockerfile to use build secrets
                    $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                    $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                    if ($this->force_rebuild) {
                        $build_command = "DOCKER_BUILDKIT=1 docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    } else {
                        $build_command = "DOCKER_BUILDKIT=1 docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    }
                } else {
                    // Traditional build with args
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
                    }
                }
                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
            } else {
                if ($this->application->build_pack === 'nixpacks') {
                    $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee ".self::NIXPACKS_PLAN_PATH.' > /dev/null'), 'hidden' => true]);
                    if ($this->force_rebuild) {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --no-cache --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ], [
                            executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                            'hidden' => true,
                        ]);
                        if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                            // Modify the nixpacks Dockerfile to use build secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } elseif ($this->dockerBuildkitSupported) {
                            // BuildKit without secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    } else {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ], [
                            executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                            'hidden' => true,
                        ]);
                        if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                            // Modify the nixpacks Dockerfile to use build secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } elseif ($this->dockerBuildkitSupported) {
                            // BuildKit without secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    }
                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ]
                    );
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm '.self::NIXPACKS_PLAN_PATH), 'hidden' => true]);
                } else {
                    // Dockerfile buildpack
                    if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
                        // Modify the Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                        // Use BuildKit with secrets
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        }
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    } else {
                        // Traditional build with args
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        }
                    }
                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ]
                    );
                }
            }
        }
        $this->application_deployment_queue->addLogEntry('Building docker image completed.');
    }

    private function graceful_shutdown_container(string $containerName, bool $skipRemove = false)
    {
        try {
            $timeout = isDev() ? 1 : 30;
            if ($skipRemove) {
                $this->execute_remote_command(
                    ["docker stop -t $timeout $containerName", 'hidden' => true, 'ignore_errors' => true]
                );
            } else {
                $this->execute_remote_command(
                    ["docker stop -t $timeout $containerName", 'hidden' => true, 'ignore_errors' => true],
                    ["docker rm -f $containerName", 'hidden' => true, 'ignore_errors' => true]
                );
            }
        } catch (Exception $error) {
            $this->application_deployment_queue->addLogEntry("Error stopping container $containerName: ".$error->getMessage(), 'stderr');
        }
    }

    private function stop_running_container(bool $force = false)
    {
        try {
            $this->application_deployment_queue->addLogEntry('Removing old containers.');
            if ($this->newVersionIsHealthy || $force) {
                if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                    $this->graceful_shutdown_container($this->container_name);
                } else {
                    $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
                    if ($this->pull_request_id === 0) {
                        $containers = $containers->filter(function ($container) {
                            return data_get($container, 'Names') !== $this->container_name && data_get($container, 'Names') !== addPreviewDeploymentSuffix($this->container_name, $this->pull_request_id);
                        });
                    }
                    $containers->each(function ($container) {
                        $this->graceful_shutdown_container(data_get($container, 'Names'));
                    });
                }
            } else {
                if ($this->application->dockerfile || $this->application->build_pack === 'dockerfile' || $this->application->build_pack === 'dockerimage') {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    $this->application_deployment_queue->addLogEntry("WARNING: Dockerfile or Docker Image based deployment detected. The healthcheck needs a curl or wget command to check the health of the application. Please make sure that it is available in the image or turn off healthcheck on Coolify's UI.");
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                }
                $this->application_deployment_queue->addLogEntry('New container is not healthy, rolling back to the old container.');
                $this->failDeployment();
                $this->graceful_shutdown_container($this->container_name);
            }
        } catch (Exception $e) {
            // If new version is healthy, this is just cleanup - don't fail the deployment
            if ($this->newVersionIsHealthy || $force) {
                $this->application_deployment_queue->addLogEntry(
                    "Warning: Could not remove old container: {$e->getMessage()}",
                    'stderr',
                    hidden: true
                );

                return; // Don't re-throw - cleanup failures shouldn't fail successful deployments
            }

            // Only re-throw if deployment hasn't succeeded yet
            throw new DeploymentException("Failed to stop running container: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    private function start_by_compose_file()
    {
        try {
            // Ensure .env file exists before docker compose tries to load it (defensive programming)
            $this->execute_remote_command(
                ["touch {$this->configuration_dir}/.env", 'hidden' => true],
            );

            if ($this->application->build_pack === 'dockerimage') {
                $this->application_deployment_queue->addLogEntry('Pulling latest images from the registry.');
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} pull"), 'hidden' => true],
                    [executeInDocker($this->deployment_uuid, "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} up --build -d"), 'hidden' => true],
                );
            } else {
                if ($this->use_build_server) {
                    $this->execute_remote_command(
                        ["{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->configuration_dir} -f {$this->configuration_dir}{$this->docker_compose_location} up --pull always --build -d", 'hidden' => true],
                    );
                } else {
                    $this->execute_remote_command(
                        [executeInDocker($this->deployment_uuid, "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} up --build -d"), 'hidden' => true],
                    );
                }
            }
            $this->application_deployment_queue->addLogEntry('New container started.');
        } catch (Exception $e) {
            throw new DeploymentException("Failed to start container: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    private function analyzeBuildTimeVariables($variables)
    {
        $userDefinedVariables = collect([]);

        $dbVariables = $this->pull_request_id === 0
            ? $this->application->environment_variables()
                ->where('is_buildtime', true)
                ->pluck('key')
            : $this->application->environment_variables_preview()
                ->where('is_buildtime', true)
                ->pluck('key');

        foreach ($variables as $key => $value) {
            if ($dbVariables->contains($key)) {
                $userDefinedVariables->put($key, $value);
            }
        }

        if ($userDefinedVariables->isEmpty()) {
            return;
        }

        $variablesArray = $userDefinedVariables->toArray();
        $warnings = self::analyzeBuildVariables($variablesArray);

        if (empty($warnings)) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        foreach ($warnings as $warning) {
            $messages = self::formatBuildWarning($warning);
            foreach ($messages as $message) {
                $this->application_deployment_queue->addLogEntry($message, type: 'warning');
            }
            $this->application_deployment_queue->addLogEntry('');
        }

        // Add general advice
        $this->application_deployment_queue->addLogEntry('💡 Tips to resolve build issues:', type: 'info');
        $this->application_deployment_queue->addLogEntry('   1. Set these variables as "Runtime only" in the environment variables settings', type: 'info');
        $this->application_deployment_queue->addLogEntry('   2. Use different values for build-time (e.g., NODE_ENV=development for build)', type: 'info');
        $this->application_deployment_queue->addLogEntry('   3. Consider using multi-stage Docker builds to separate build and runtime environments', type: 'info');
    }

    private function generate_build_env_variables()
    {
        if ($this->application->build_pack === 'nixpacks') {
            $variables = collect($this->nixpacks_plan_json->get('variables'));
        } else {
            $this->generate_env_variables();
            $variables = collect([])->merge($this->env_args);
        }
        // Analyze build variables for potential issues
        if ($variables->isNotEmpty()) {
            $this->analyzeBuildTimeVariables($variables);
        }

        if ($this->dockerBuildkitSupported && $this->application->settings->use_build_secrets) {
            $this->generate_build_secrets($variables);
            $this->build_args = '';
        } else {
            $secrets_hash = '';
            if ($variables->isNotEmpty()) {
                $secrets_hash = $this->generate_secrets_hash($variables);
            }

            $env_vars = $this->pull_request_id === 0
                ? $this->application->environment_variables()->where('is_buildtime', true)->get()
                : $this->application->environment_variables_preview()->where('is_buildtime', true)->get();

            // Map variables to include is_multiline flag
            $vars_with_metadata = $variables->map(function ($value, $key) use ($env_vars) {
                $env = $env_vars->firstWhere('key', $key);

                return [
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => $env ? $env->is_multiline : false,
                ];
            });

            $this->build_args = generateDockerBuildArgs($vars_with_metadata);

            if ($secrets_hash) {
                $this->build_args->push("--build-arg COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}");
            }
        }
    }

    private function generate_docker_env_flags_for_secrets()
    {
        // Only generate env flags if build secrets are enabled
        if (! $this->application->settings->use_build_secrets) {
            return '';
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($variables->isEmpty()) {
            return '';
        }

        $secrets_hash = $this->generate_secrets_hash($variables);

        // Get database env vars to check for multiline flag
        $env_vars = $this->pull_request_id === 0
            ? $this->application->environment_variables()->where('is_buildtime', true)->get()
            : $this->application->environment_variables_preview()->where('is_buildtime', true)->get();

        // Map to simple array format for the helper function
        $vars_array = $variables->map(function ($value, $key) use ($env_vars) {
            $env = $env_vars->firstWhere('key', $key);

            return [
                'key' => $key,
                'value' => $value,
                'is_multiline' => $env ? $env->is_multiline : false,
            ];
        });

        $env_flags = generateDockerEnvFlags($vars_array);
        $env_flags .= " -e COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}";

        return $env_flags;
    }

    private function generate_build_secrets(Collection $variables)
    {
        if ($variables->isEmpty()) {
            $this->build_secrets = '';

            return;
        }

        $this->build_secrets = $variables
            ->map(function ($value, $key) {
                return "--secret id={$key},env={$key}";
            })
            ->implode(' ');

        $this->build_secrets .= ' --secret id=COOLIFY_BUILD_SECRETS_HASH,env=COOLIFY_BUILD_SECRETS_HASH';
    }

    private function generate_secrets_hash($variables)
    {
        if (! $this->secrets_hash_key) {
            // Use APP_KEY as deterministic hash key to preserve Docker build cache
            // Random keys would change every deployment, breaking cache even when secrets haven't changed
            $this->secrets_hash_key = config('app.key');
        }

        if ($variables instanceof Collection) {
            $secrets_string = $variables
                ->mapWithKeys(function ($value, $key) {
                    return [$key => $value];
                })
                ->sortKeys()
                ->map(function ($value, $key) {
                    return "{$key}={$value}";
                })
                ->implode('|');
        } else {
            $secrets_string = $variables
                ->map(function ($env) {
                    return "{$env->key}={$env->real_value}";
                })
                ->sort()
                ->implode('|');
        }

        return hash_hmac('sha256', $secrets_string, $this->secrets_hash_key);
    }

    protected function findFromInstructionLines($dockerfile): array
    {
        $fromLines = [];
        foreach ($dockerfile as $index => $line) {
            $trimmedLine = trim($line);
            // Check if line starts with FROM (case-insensitive)
            if (preg_match('/^FROM\s+/i', $trimmedLine)) {
                $fromLines[] = $index;
            }
        }

        return $fromLines;
    }

    private function add_build_env_variables_to_dockerfile()
    {
        if ($this->dockerBuildkitSupported) {
            // We dont need to add build secrets to dockerfile for buildkit, as we already added them with --secret flag in function generate_docker_env_flags_for_secrets
            return;
        }

        // Skip ARG injection if disabled by user - preserves Docker build cache
        if ($this->application->settings->inject_build_args_to_dockerfile === false) {
            $this->application_deployment_queue->addLogEntry('Skipping Dockerfile ARG injection (disabled in settings).', hidden: true);

            return;
        }

        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
            'hidden' => true,
            'save' => 'dockerfile',
            'ignore_errors' => true,
        ]);
        $dockerfile = collect(str($this->saved_outputs->get('dockerfile'))->trim()->explode("\n"));

        // Find all FROM instruction positions
        $fromLines = $this->findFromInstructionLines($dockerfile);

        // If no FROM instructions found, skip ARG insertion
        if (empty($fromLines)) {
            return;
        }

        // Collect all ARG statements to insert
        $argsToInsert = collect();

        if ($this->pull_request_id === 0) {
            // Only add environment variables that are available during build
            $envs = $this->application->environment_variables()
                ->where('key', 'not like', 'NIXPACKS_%')
                ->where('is_buildtime', true)
                ->get();
            foreach ($envs as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $argsToInsert->push("ARG {$env->key}");
                } else {
                    $argsToInsert->push("ARG {$env->key}={$env->real_value}");
                }
            }
            // Add Coolify variables as ARGs
            if ($this->coolify_variables) {
                $coolify_vars = collect(explode(' ', trim($this->coolify_variables)))
                    ->filter()
                    ->map(function ($var) {
                        return "ARG {$var}";
                    });
                $argsToInsert = $argsToInsert->merge($coolify_vars);
            }
        } else {
            // Only add preview environment variables that are available during build
            $envs = $this->application->environment_variables_preview()
                ->where('key', 'not like', 'NIXPACKS_%')
                ->where('is_buildtime', true)
                ->get();
            foreach ($envs as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $argsToInsert->push("ARG {$env->key}");
                } else {
                    $argsToInsert->push("ARG {$env->key}={$env->real_value}");
                }
            }
            // Add Coolify variables as ARGs
            if ($this->coolify_variables) {
                $coolify_vars = collect(explode(' ', trim($this->coolify_variables)))
                    ->filter()
                    ->map(function ($var) {
                        return "ARG {$var}";
                    });
                $argsToInsert = $argsToInsert->merge($coolify_vars);
            }
        }

        // Development logging to show what ARGs are being injected
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] Dockerfile ARG Injection');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ARGs to inject: '.$argsToInsert->count());
            foreach ($argsToInsert as $arg) {
                // Only show ARG key, not the value (for security)
                $argKey = str($arg)->after('ARG ')->before('=')->toString();
                $this->application_deployment_queue->addLogEntry("[DEBUG]   - {$argKey}");
            }
        }

        // Insert ARGs after each FROM instruction (in reverse order to maintain correct line numbers)
        if ($argsToInsert->isNotEmpty()) {
            foreach (array_reverse($fromLines) as $fromLineIndex) {
                // Insert all ARGs after this FROM instruction
                foreach ($argsToInsert->reverse() as $arg) {
                    $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
                }
            }
            $envs_mapped = $envs->mapWithKeys(function ($env) {
                return [$env->key => $env->real_value];
            });
            $secrets_hash = $this->generate_secrets_hash($envs_mapped);
            $argsToInsert->push("ARG COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}");
        }

        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->application_deployment_queue->addLogEntry('Final Dockerfile:', type: 'info', hidden: true);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$this->workdir}{$this->dockerfile_location} > /dev/null"),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
                'hidden' => true,
                'ignore_errors' => true,
            ]);
    }

    private function modify_dockerfile_for_secrets($dockerfile_path)
    {
        // Only process if build secrets are enabled and we have secrets to mount
        if (! $this->application->settings->use_build_secrets || empty($this->build_secrets)) {
            return;
        }

        // Read the Dockerfile
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$dockerfile_path}"),
            'hidden' => true,
            'save' => 'dockerfile_content',
        ]);

        $dockerfile = str($this->saved_outputs->get('dockerfile_content'))->trim()->explode("\n");

        // Add BuildKit syntax directive if not present
        if (! str_starts_with($dockerfile->first(), '# syntax=')) {
            $dockerfile->prepend('# syntax=docker/dockerfile:1');
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;
        if ($variables->isEmpty()) {
            return;
        }

        // Generate mount strings for all secrets
        $mountStrings = $variables->map(fn ($value, $key) => "--mount=type=secret,id={$key},env={$key}")->implode(' ');

        // Add mount for the secrets hash to ensure cache invalidation
        $mountStrings .= ' --mount=type=secret,id=COOLIFY_BUILD_SECRETS_HASH,env=COOLIFY_BUILD_SECRETS_HASH';

        $modified = false;
        $dockerfile = $dockerfile->map(function ($line) use ($mountStrings, &$modified) {
            $trimmed = ltrim($line);

            // Skip lines that already have secret mounts or are not RUN commands
            if (str_contains($line, '--mount=type=secret') || ! str_starts_with($trimmed, 'RUN')) {
                return $line;
            }

            // Add mount strings to RUN command
            $originalCommand = trim(substr($trimmed, 3));
            $modified = true;

            return "RUN {$mountStrings} {$originalCommand}";
        });

        if ($modified) {
            // Write the modified Dockerfile back
            $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$dockerfile_path} > /dev/null"),
                'hidden' => true,
            ]);
        }
    }

    private function modify_dockerfiles_for_compose($composeFile)
    {
        if ($this->application->build_pack !== 'dockercompose') {
            return;
        }

        // Skip ARG injection if disabled by user - preserves Docker build cache
        if ($this->application->settings->inject_build_args_to_dockerfile === false) {
            $this->application_deployment_queue->addLogEntry('Skipping Docker Compose Dockerfile ARG injection (disabled in settings).', hidden: true);

            return;
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($variables->isEmpty()) {
            $this->application_deployment_queue->addLogEntry('No build-time variables to add to Dockerfiles.');

            return;
        }

        $services = data_get($composeFile, 'services', []);

        foreach ($services as $serviceName => $service) {
            if (! isset($service['build'])) {
                continue;
            }

            $context = '.';
            $dockerfile = 'Dockerfile';

            if (is_string($service['build'])) {
                $context = $service['build'];
            } elseif (is_array($service['build'])) {
                $context = data_get($service['build'], 'context', '.');
                $dockerfile = data_get($service['build'], 'dockerfile', 'Dockerfile');
            }

            $dockerfilePath = rtrim($context, '/').'/'.ltrim($dockerfile, '/');
            if (str_starts_with($dockerfilePath, './')) {
                $dockerfilePath = substr($dockerfilePath, 2);
            }
            if (str_starts_with($dockerfilePath, '/')) {
                $dockerfilePath = substr($dockerfilePath, 1);
            }

            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "test -f {$this->workdir}/{$dockerfilePath} && echo 'exists' || echo 'not found'"),
                'hidden' => true,
                'save' => 'dockerfile_check_'.$serviceName,
            ]);

            if (str($this->saved_outputs->get('dockerfile_check_'.$serviceName))->trim()->toString() !== 'exists') {
                $this->application_deployment_queue->addLogEntry("Dockerfile not found for service {$serviceName} at {$dockerfilePath}, skipping ARG injection.");

                continue;
            }

            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}/{$dockerfilePath}"),
                'hidden' => true,
                'save' => 'dockerfile_content_'.$serviceName,
            ]);

            $dockerfileContent = $this->saved_outputs->get('dockerfile_content_'.$serviceName);
            if (! $dockerfileContent) {
                continue;
            }

            $dockerfile_lines = collect(str($dockerfileContent)->trim()->explode("\n"));

            $fromIndices = [];
            $dockerfile_lines->each(function ($line, $index) use (&$fromIndices) {
                if (str($line)->trim()->startsWith('FROM')) {
                    $fromIndices[] = $index;
                }
            });

            if (empty($fromIndices)) {
                $this->application_deployment_queue->addLogEntry("No FROM instruction found in Dockerfile for service {$serviceName}, skipping.");

                continue;
            }

            $isMultiStage = count($fromIndices) > 1;

            $argsToAdd = collect([]);
            foreach ($variables as $key => $value) {
                $argsToAdd->push("ARG {$key}");
            }

            if ($argsToAdd->isEmpty()) {
                $this->application_deployment_queue->addLogEntry("Service {$serviceName}: No build-time variables to add.");

                continue;
            }

            // Development logging to show what ARGs are being injected for Docker Compose
            if (isDev()) {
                $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
                $this->application_deployment_queue->addLogEntry("[DEBUG] Docker Compose ARG Injection - Service: {$serviceName}");
                $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
                $this->application_deployment_queue->addLogEntry('[DEBUG] ARGs to inject: '.$argsToAdd->count());
                foreach ($argsToAdd as $arg) {
                    $argKey = str($arg)->after('ARG ')->toString();
                    $this->application_deployment_queue->addLogEntry("[DEBUG]   - {$argKey}");
                }
            }

            $totalAdded = 0;
            $offset = 0;

            foreach ($fromIndices as $stageIndex => $fromIndex) {
                $adjustedIndex = $fromIndex + $offset;

                $stageStart = $adjustedIndex + 1;
                $stageEnd = isset($fromIndices[$stageIndex + 1])
                    ? $fromIndices[$stageIndex + 1] + $offset
                    : $dockerfile_lines->count();

                $existingStageArgs = collect([]);
                for ($i = $stageStart; $i < $stageEnd; $i++) {
                    $line = $dockerfile_lines->get($i);
                    if (! $line || ! str($line)->trim()->startsWith('ARG')) {
                        break;
                    }
                    $parts = explode(' ', trim($line), 2);
                    if (count($parts) >= 2) {
                        $argPart = $parts[1];
                        $keyValue = explode('=', $argPart, 2);
                        $existingStageArgs->push($keyValue[0]);
                    }
                }

                $stageArgsToAdd = $argsToAdd->filter(function ($arg) use ($existingStageArgs) {
                    $key = str($arg)->after('ARG ')->trim()->toString();

                    return ! $existingStageArgs->contains($key);
                });

                if ($stageArgsToAdd->isNotEmpty()) {
                    $dockerfile_lines->splice($adjustedIndex + 1, 0, $stageArgsToAdd->toArray());
                    $totalAdded += $stageArgsToAdd->count();
                    $offset += $stageArgsToAdd->count();
                }
            }

            if ($totalAdded > 0) {
                $dockerfile_base64 = base64_encode($dockerfile_lines->implode("\n"));
                $this->execute_remote_command([
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$this->workdir}/{$dockerfilePath} > /dev/null"),
                    'hidden' => true,
                ]);

                $stageInfo = $isMultiStage ? ' (multi-stage build, added to '.count($fromIndices).' stages)' : '';
                $this->application_deployment_queue->addLogEntry("Added {$totalAdded} ARG declarations to Dockerfile for service {$serviceName}{$stageInfo}.");
            } else {
                $this->application_deployment_queue->addLogEntry("Service {$serviceName}: All required ARG declarations already exist.");
            }

            if ($this->application->settings->use_build_secrets && $this->dockerBuildkitSupported && ! empty($this->build_secrets)) {
                $fullDockerfilePath = "{$this->workdir}/{$dockerfilePath}";
                $this->modify_dockerfile_for_secrets($fullDockerfilePath);
                $this->application_deployment_queue->addLogEntry("Modified Dockerfile for service {$serviceName} to use build secrets.");
            }
        }
    }

    private function add_build_secrets_to_compose($composeFile)
    {
        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($variables->isEmpty()) {
            return $composeFile;
        }

        $secrets = [];
        foreach ($variables as $key => $value) {
            $secrets[$key] = [
                'environment' => $key,
            ];
        }

        $services = data_get($composeFile, 'services', []);
        foreach ($services as $serviceName => &$service) {
            if (isset($service['build'])) {
                if (is_string($service['build'])) {
                    $service['build'] = [
                        'context' => $service['build'],
                    ];
                }
                if (! isset($service['build']['secrets'])) {
                    $service['build']['secrets'] = [];
                }
                foreach ($variables as $key => $value) {
                    if (! in_array($key, $service['build']['secrets'])) {
                        $service['build']['secrets'][] = $key;
                    }
                }
            }
        }

        $composeFile['services'] = $services;
        $existingSecrets = data_get($composeFile, 'secrets', []);
        if ($existingSecrets instanceof \Illuminate\Support\Collection) {
            $existingSecrets = $existingSecrets->toArray();
        }
        $composeFile['secrets'] = array_replace($existingSecrets, $secrets);

        $this->application_deployment_queue->addLogEntry('Added build secrets configuration to docker-compose file (using environment variables).');

        return $composeFile;
    }

    private function run_pre_deployment_command()
    {
        if (empty($this->application->pre_deployment_command)) {
            return;
        }
        $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
        if ($containers->count() == 0) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('Executing pre-deployment command (see debug log for output/errors).');

        foreach ($containers as $container) {
            $containerName = data_get($container, 'Names');
            if ($containers->count() == 1 || str_starts_with($containerName, $this->application->pre_deployment_command_container.'-'.$this->application->uuid)) {
                $cmd = "sh -c '".str_replace("'", "'\''", $this->application->pre_deployment_command)."'";
                $exec = "docker exec {$containerName} {$cmd}";
                $this->execute_remote_command(
                    [
                        'command' => $exec,
                        'hidden' => true,
                    ],
                );

                return;
            }
        }
        throw new DeploymentException('Pre-deployment command: Could not find a valid container. Is the container name correct?');
    }

    private function run_post_deployment_command()
    {
        if (empty($this->application->post_deployment_command)) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Executing post-deployment command (see debug log for output).');

        $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
        foreach ($containers as $container) {
            $containerName = data_get($container, 'Names');
            if ($containers->count() == 1 || str_starts_with($containerName, $this->application->post_deployment_command_container.'-'.$this->application->uuid)) {
                $cmd = "sh -c '".str_replace("'", "'\''", $this->application->post_deployment_command)."'";
                $exec = "docker exec {$containerName} {$cmd}";
                try {
                    $this->execute_remote_command(
                        [
                            'command' => $exec,
                            'hidden' => true,
                            'save' => 'post-deployment-command-output',
                        ],
                    );
                } catch (Exception $e) {
                    $post_deployment_command_output = $this->saved_outputs->get('post-deployment-command-output');
                    if ($post_deployment_command_output) {
                        $this->application_deployment_queue->addLogEntry('Post-deployment command failed.');
                        $this->application_deployment_queue->addLogEntry($post_deployment_command_output, 'stderr');
                    }
                }

                return;
            }
        }
        throw new DeploymentException('Post-deployment command: Could not find a valid container. Is the container name correct?');
    }

    /**
     * Check if the deployment was cancelled and abort if it was
     */
    private function checkForCancellation(): void
    {
        $this->application_deployment_queue->refresh();
        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->addLogEntry('Deployment cancelled by user, stopping execution.');
            throw new DeploymentException('Deployment cancelled by user', 69420);
        }
    }

    /**
     * Transition deployment to a new status with proper validation and side effects.
     * This is the single source of truth for status transitions.
     */
    private function transitionToStatus(ApplicationDeploymentStatus $status): void
    {
        if ($this->isInTerminalState()) {
            return;
        }

        $this->updateDeploymentStatus($status);
        $this->handleStatusTransition($status);
        queue_next_deployment($this->application);
    }

    /**
     * Check if deployment is in a terminal state (FINISHED, FAILED or CANCELLED).
     * Terminal states cannot be changed.
     */
    private function isInTerminalState(): bool
    {
        $this->application_deployment_queue->refresh();

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FINISHED->value) {
            return true;
        }

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FAILED->value) {
            return true;
        }

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->addLogEntry('Deployment cancelled by user, stopping execution.');
            throw new DeploymentException('Deployment cancelled by user', 69420);
        }

        return false;
    }

    /**
     * Update the deployment status in the database.
     */
    private function updateDeploymentStatus(ApplicationDeploymentStatus $status): void
    {
        $this->application_deployment_queue->update([
            'status' => $status->value,
        ]);
    }

    /**
     * Execute status-specific side effects (events, notifications, additional deployments).
     */
    private function handleStatusTransition(ApplicationDeploymentStatus $status): void
    {
        match ($status) {
            ApplicationDeploymentStatus::FINISHED => $this->handleSuccessfulDeployment(),
            ApplicationDeploymentStatus::FAILED => $this->handleFailedDeployment(),
            default => null,
        };
    }

    /**
     * Handle side effects when deployment succeeds.
     */
    private function handleSuccessfulDeployment(): void
    {
        // Reset restart count after successful deployment
        // This is done here (not in Livewire) to avoid race conditions
        // with GetContainersStatus reading old container restart counts
        $this->application->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        event(new ApplicationConfigurationChanged($this->application->team()->id));

        if (! $this->only_this_server) {
            $this->deploy_to_additional_destinations();
        }

        $this->sendDeploymentNotification(DeploymentSuccess::class);
    }

    /**
     * Handle side effects when deployment fails.
     */
    private function handleFailedDeployment(): void
    {
        $this->sendDeploymentNotification(DeploymentFailed::class);
    }

    /**
     * Send deployment status notification to the team.
     */
    private function sendDeploymentNotification(string $notificationClass): void
    {
        $this->application->environment->project->team?->notify(
            new $notificationClass($this->application, $this->deployment_uuid, $this->preview)
        );
    }

    /**
     * Complete deployment successfully.
     * Sends success notification and triggers additional deployments if needed.
     */
    private function completeDeployment(): void
    {
        $this->transitionToStatus(ApplicationDeploymentStatus::FINISHED);
    }

    /**
     * Fail the deployment.
     * Sends failure notification and queues next deployment.
     */
    protected function failDeployment(): void
    {
        $this->transitionToStatus(ApplicationDeploymentStatus::FAILED);
    }

    public function failed(Throwable $exception): void
    {
        $this->failDeployment();

        // Log comprehensive error information
        $errorMessage = $exception->getMessage() ?: 'Unknown error occurred';
        $errorCode = $exception->getCode();
        $errorClass = get_class($exception);

        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');
        $this->application_deployment_queue->addLogEntry("Deployment failed: {$errorMessage}", 'stderr');
        $this->application_deployment_queue->addLogEntry("Error type: {$errorClass}", 'stderr', hidden: true);
        $this->application_deployment_queue->addLogEntry("Error code: {$errorCode}", 'stderr', hidden: true);

        // Log the exception file and line for debugging
        $this->application_deployment_queue->addLogEntry("Location: {$exception->getFile()}:{$exception->getLine()}", 'stderr', hidden: true);

        // Log previous exceptions if they exist (for chained exceptions)
        $previous = $exception->getPrevious();
        if ($previous) {
            $this->application_deployment_queue->addLogEntry('Caused by:', 'stderr', hidden: true);
            $previousMessage = $previous->getMessage() ?: 'No message';
            $previousClass = get_class($previous);
            $this->application_deployment_queue->addLogEntry("  {$previousClass}: {$previousMessage}", 'stderr', hidden: true);
            $this->application_deployment_queue->addLogEntry("  at {$previous->getFile()}:{$previous->getLine()}", 'stderr', hidden: true);
        }

        // Log first few lines of stack trace for debugging
        $trace = $exception->getTraceAsString();
        $traceLines = explode("\n", $trace);
        $this->application_deployment_queue->addLogEntry('Stack trace (first 5 lines):', 'stderr', hidden: true);
        foreach (array_slice($traceLines, 0, 5) as $traceLine) {
            $this->application_deployment_queue->addLogEntry("  {$traceLine}", 'stderr', hidden: true);
        }
        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');

        if ($this->application->build_pack !== 'dockercompose') {
            $code = $exception->getCode();
            if ($code !== 69420) {
                // 69420 means failed to push the image to the registry, so we don't need to remove the new version as it is the currently running one
                if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty() || $this->pull_request_id !== 0) {
                    // do not remove already running container for PR deployments
                } else {
                    $this->application_deployment_queue->addLogEntry('Deployment failed. Removing the new version of your application.', 'stderr');
                    $this->execute_remote_command(
                        ["docker rm -f $this->container_name >/dev/null 2>&1", 'hidden' => true, 'ignore_errors' => true]
                    );
                }
            }
        }
    }
}
