<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Traits\ExecuteRemoteCommand;
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
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;
use Yosymfony\Toml\Toml;

class ApplicationDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public static int $batch_counter = 0;

    private int $application_deployment_queue_id;

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

    private $environment_variables;

    private $env_nixpacks_args;

    private $docker_compose;

    private $docker_compose_base64;

    private ?string $env_filename = null;

    private ?string $nixpacks_plan = null;

    private ?string $nixpacks_type = null;

    private string $dockerfile_location = '/Dockerfile';

    private string $docker_compose_location = '/docker-compose.yaml';

    private ?string $docker_compose_custom_start_command = null;

    private ?string $docker_compose_custom_build_command = null;

    private ?string $addHosts = null;

    private ?string $buildTarget = null;

    private Collection $saved_outputs;

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

    public $tries = 1;

    public function __construct(int $application_deployment_queue_id)
    {
        $this->application_deployment_queue = ApplicationDeploymentQueue::find($application_deployment_queue_id);
        $this->application = Application::find($this->application_deployment_queue->application_id);
        $this->build_pack = data_get($this->application, 'build_pack');
        $this->build_args = collect([]);

        $this->application_deployment_queue_id = $application_deployment_queue_id;
        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->rollback = $this->application_deployment_queue->rollback;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;
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
                $this->container_name = "{$this->application->settings->custom_internal_name}-pr-{$this->pull_request_id}";
            }
        }
        ray('New container name: ', $this->container_name)->green();

        savePrivateKeyToFs($this->server);
        $this->saved_outputs = collect();

        // Set preview fqdn
        if ($this->pull_request_id !== 0) {
            $this->preview = $this->application->generate_preview_fqdn($this->pull_request_id);
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
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);
        if (! $this->server->isFunctional()) {
            $this->application_deployment_queue->addLogEntry('Server is not functional.');
            $this->fail('Server is not functional.');

            return;
        }
        try {
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
            $this->decide_what_to_do();
        } catch (Exception $e) {
            if ($this->pull_request_id !== 0 && $this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::ERROR);
            }
            ray($e);
            $this->fail($e);
            throw $e;
        } finally {
            if ($this->use_build_server) {
                $this->server = $this->build_server;
            } else {
                $this->write_deployment_configurations();
            }
            $this->execute_remote_command(
                [
                    "docker rm -f {$this->deployment_uuid} >/dev/null 2>&1",
                    'hidden' => true,
                    'ignore_errors' => true,
                ]
            );

            ApplicationStatusChanged::dispatch(data_get($this->application, 'environment.project.team.id'));
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
        if ($this->server->isProxyShouldRun()) {
            GetContainersStatus::dispatch($this->server)->onQueue('high');
            // dispatch(new ContainerStatusJob($this->server));
        }
        $this->next(ApplicationDeploymentStatus::FINISHED->value);
        if ($this->pull_request_id !== 0) {
            if ($this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::FINISHED);
            }
        }
        $this->run_post_deployment_command();
        $this->application->isConfigurationChanged(true);
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
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
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
        ray("echo 'Starting deployment of {$this->dockerImage}:{$this->dockerImageTag} to {$this->server->name}.'");
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->dockerImage}:{$this->dockerImageTag} to {$this->server->name}.");
        $this->generate_image_names();
        $this->prepare_builder_image();
        $this->generate_compose_file();
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
        $this->application->loadComposeFile(isInit: false);
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            $this->application->oldRawParser();
            $yaml = $composeFile = $this->application->docker_compose_raw;
            $this->save_environment_variables();
        } else {
            $composeFile = $this->application->parse(pull_request_id: $this->pull_request_id, preview_id: data_get($this->preview, 'id'));
            $this->save_environment_variables();
            if (! is_null($this->env_filename)) {
                $services = collect($composeFile['services']);
                $services = $services->map(function ($service, $name) {
                    $service['env_file'] = [$this->env_filename];

                    return $service;
                });
                $composeFile['services'] = $services->toArray();
            }
            if (is_null($composeFile)) {
                $this->application_deployment_queue->addLogEntry('Failed to parse docker-compose file.');
                $this->fail('Failed to parse docker-compose file.');

                return;
            }
            $yaml = Yaml::dump(convertToArray($composeFile), 10);
        }
        $this->docker_compose_base64 = base64_encode($yaml);
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}{$this->docker_compose_location} > /dev/null"),
            'hidden' => true,
        ]);
        // Build new container to limit downtime.
        $this->application_deployment_queue->addLogEntry('Pulling & building required images.');

        if ($this->docker_compose_custom_build_command) {
            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$this->docker_compose_custom_build_command}"), 'hidden' => true],
            );
        } else {
            $command = "{$this->coolify_variables} docker compose";
            if ($this->env_filename) {
                $command .= " --env-file {$this->workdir}/{$this->env_filename}";
            }
            $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} build --pull";
            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, $command), 'hidden' => true],
            );
        }

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
                "docker network connect {$networkId} coolify-proxy || true",
                'hidden' => true,
                'ignore_errors' => true,
            ]);
        }

        // Start compose file
        $server_workdir = $this->application->workdir();
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            if ($this->docker_compose_custom_start_command) {
                $this->write_deployment_configurations();
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "cd {$this->workdir} && {$this->docker_compose_custom_start_command}"), 'hidden' => true],
                );
            } else {
                $this->write_deployment_configurations();
                $this->docker_compose_location = '/docker-compose.yaml';

                $command = "{$this->coolify_variables} docker compose";
                if ($this->env_filename) {
                    $command .= " --env-file {$server_workdir}/{$this->env_filename}";
                }
                $command .= " --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                $this->execute_remote_command(
                    ['command' => $command, 'hidden' => true],
                );
            }
        } else {
            if ($this->docker_compose_custom_start_command) {
                $this->write_deployment_configurations();
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$this->docker_compose_custom_start_command}"), 'hidden' => true],
                );
            } else {
                $command = "{$this->coolify_variables} docker compose";
                if ($this->preserveRepository) {
                    if ($this->env_filename) {
                        $command .= " --env-file {$server_workdir}/{$this->env_filename}";
                    }
                    $command .= " --project-name {$this->application->uuid} --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                    $this->write_deployment_configurations();

                    $this->execute_remote_command(
                        ['command' => $command, 'hidden' => true],
                    );
                } else {
                    if ($this->env_filename) {
                        $command .= " --env-file {$this->workdir}/{$this->env_filename}";
                    }
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
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
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
        $this->generate_build_env_variables();
        $this->build_image();
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
        $this->build_image();
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
                $composeFileName = "$mainDir/docker-compose-pr-{$this->pull_request_id}.yaml";
                $this->docker_compose_location = "/docker-compose-pr-{$this->pull_request_id}.yaml";
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
            ray('empty docker_registry_image_name');

            return;
        }
        if ($this->restart_only) {
            ray('restart_only');

            return;
        }
        if ($this->application->build_pack === 'dockerimage') {
            ray('dockerimage');

            return;
        }
        if ($this->use_build_server) {
            ray('use_build_server');
            $forceFail = true;
        }
        if ($this->server->isSwarm() && $this->build_pack !== 'dockerimage') {
            ray('isSwarm');
            $forceFail = true;
        }
        if ($this->application->additional_servers->count() > 0) {
            ray('additional_servers');
            $forceFail = true;
        }
        if ($this->is_this_additional_server) {
            ray('this is an additional_servers, no pushy pushy');

            return;
        }
        ray('push_to_docker_registry noww: '.$this->production_image_name);
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
                throw new RuntimeException($e->getMessage(), 69420);
            }
            ray($e);
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
            $this->production_image_name = "{$this->dockerImage}:{$this->dockerImageTag}";
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
        $this->next(ApplicationDeploymentStatus::FINISHED->value);
    }

    private function should_skip_build()
    {
        if (str($this->saved_outputs->get('local_image_found'))->isNotEmpty()) {
            if ($this->is_this_additional_server) {
                $this->application_deployment_queue->addLogEntry("Image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->generate_compose_file();
                $this->push_to_docker_registry();
                $this->rolling_update();
                if ($this->restart_only) {
                    $this->post_deployment();
                }

                return true;
            }
            if (! $this->application->isConfigurationChanged()) {
                $this->application_deployment_queue->addLogEntry("No configuration changed & image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->generate_compose_file();
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

    private function save_environment_variables()
    {
        $envs = collect([]);
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            $local_branch = "pull/{$this->pull_request_id}/head";
        }
        $sort = $this->application->settings->is_env_sorting_enabled;
        if ($sort) {
            $sorted_environment_variables = $this->application->environment_variables->sortBy('key');
            $sorted_environment_variables_preview = $this->application->environment_variables_preview->sortBy('key');
        } else {
            $sorted_environment_variables = $this->application->environment_variables->sortBy('id');
            $sorted_environment_variables_preview = $this->application->environment_variables_preview->sortBy('id');
        }
        $ports = $this->application->main_port();
        if ($this->pull_request_id !== 0) {
            $this->env_filename = ".env-pr-$this->pull_request_id";
            // Add SOURCE_COMMIT if not exists
            if ($this->application->environment_variables_preview->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                if (! is_null($this->commit)) {
                    $envs->push("SOURCE_COMMIT={$this->commit}");
                } else {
                    $envs->push('SOURCE_COMMIT=unknown');
                }
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                $envs->push("COOLIFY_FQDN={$this->preview->fqdn}");
                $envs->push("COOLIFY_DOMAIN_URL={$this->preview->fqdn}");
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->preview->fqdn)->replace('http://', '')->replace('https://', '');
                $envs->push("COOLIFY_URL={$url}");
                $envs->push("COOLIFY_DOMAIN_FQDN={$url}");
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $envs->push("COOLIFY_BRANCH={$local_branch}");
                }
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                    $envs->push("COOLIFY_CONTAINER_NAME={$this->container_name}");
                }
            }

            add_coolify_default_environment_variables($this->application, $envs, $this->application->environment_variables_preview);

            foreach ($sorted_environment_variables_preview as $env) {
                $real_value = $env->real_value;
                if ($env->version === '4.0.0-beta.239') {
                    $real_value = $env->real_value;
                } else {
                    if ($env->is_literal || $env->is_multiline) {
                        $real_value = '\''.$real_value.'\'';
                    } else {
                        $real_value = escapeEnvVariables($env->real_value);
                    }
                }
                $envs->push($env->key.'='.$real_value);
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
        } else {
            $this->env_filename = '.env';
            // Add SOURCE_COMMIT if not exists
            if ($this->application->environment_variables->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                if (! is_null($this->commit)) {
                    $envs->push("SOURCE_COMMIT={$this->commit}");
                } else {
                    $envs->push('SOURCE_COMMIT=unknown');
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                if ($this->application->compose_parsing_version === '3') {
                    $envs->push("COOLIFY_URL={$this->application->fqdn}");
                } else {
                    $envs->push("COOLIFY_FQDN={$this->application->fqdn}");
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->application->fqdn)->replace('http://', '')->replace('https://', '');
                if ($this->application->compose_parsing_version === '3') {
                    $envs->push("COOLIFY_FQDN={$url}");
                } else {
                    $envs->push("COOLIFY_URL={$url}");
                }
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $envs->push("COOLIFY_BRANCH={$local_branch}");
                }
                if ($this->application->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                    $envs->push("COOLIFY_CONTAINER_NAME={$this->container_name}");
                }
            }

            add_coolify_default_environment_variables($this->application, $envs, $this->application->environment_variables);

            foreach ($sorted_environment_variables as $env) {
                $real_value = $env->real_value;
                if ($env->version === '4.0.0-beta.239') {
                    $real_value = $env->real_value;
                } else {
                    if ($env->is_literal || $env->is_multiline) {
                        $real_value = '\''.$real_value.'\'';
                    } else {
                        $real_value = escapeEnvVariables($env->real_value);
                    }
                }
                $envs->push($env->key.'='.$real_value);
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
        }
        if ($envs->isEmpty()) {
            $this->env_filename = null;
            if ($this->use_build_server) {
                $this->server = $this->original_server;
                $this->execute_remote_command(
                    [
                        'command' => "rm -f $this->configuration_dir/{$this->env_filename}",
                        'hidden' => true,
                        'ignore_errors' => true,
                    ]
                );
                $this->server = $this->build_server;
                $this->execute_remote_command(
                    [
                        'command' => "rm -f $this->configuration_dir/{$this->env_filename}",
                        'hidden' => true,
                        'ignore_errors' => true,
                    ]
                );
            } else {
                $this->execute_remote_command(
                    [
                        'command' => "rm -f $this->configuration_dir/{$this->env_filename}",
                        'hidden' => true,
                        'ignore_errors' => true,
                    ]
                );
            }
        } else {
            $envs_base64 = base64_encode($envs->implode("\n"));
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d | tee $this->workdir/{$this->env_filename} > /dev/null"),
                ],

            );
            if ($this->use_build_server) {
                $this->server = $this->original_server;
                $this->execute_remote_command(
                    [
                        "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/{$this->env_filename} > /dev/null",
                    ]
                );
                $this->server = $this->build_server;
            } else {
                $this->execute_remote_command(
                    [
                        "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/{$this->env_filename} > /dev/null",
                    ]
                );
            }
        }
        $this->environment_variables = $envs;
    }

    private function laravel_finetunes()
    {
        if ($this->pull_request_id === 0) {
            $nixpacks_php_fallback_path = $this->application->environment_variables->where('key', 'NIXPACKS_PHP_FALLBACK_PATH')->first();
            $nixpacks_php_root_dir = $this->application->environment_variables->where('key', 'NIXPACKS_PHP_ROOT_DIR')->first();
        } else {
            $nixpacks_php_fallback_path = $this->application->environment_variables_preview->where('key', 'NIXPACKS_PHP_FALLBACK_PATH')->first();
            $nixpacks_php_root_dir = $this->application->environment_variables_preview->where('key', 'NIXPACKS_PHP_ROOT_DIR')->first();
        }
        if (! $nixpacks_php_fallback_path) {
            $nixpacks_php_fallback_path = new EnvironmentVariable;
            $nixpacks_php_fallback_path->key = 'NIXPACKS_PHP_FALLBACK_PATH';
            $nixpacks_php_fallback_path->value = '/index.php';
            $nixpacks_php_fallback_path->is_build_time = false;
            $nixpacks_php_fallback_path->application_id = $this->application->id;
            $nixpacks_php_fallback_path->save();
        }
        if (! $nixpacks_php_root_dir) {
            $nixpacks_php_root_dir = new EnvironmentVariable;
            $nixpacks_php_root_dir->key = 'NIXPACKS_PHP_ROOT_DIR';
            $nixpacks_php_root_dir->value = '/app/public';
            $nixpacks_php_root_dir->is_build_time = false;
            $nixpacks_php_root_dir->application_id = $this->application->id;
            $nixpacks_php_root_dir->save();
        }

        return [$nixpacks_php_fallback_path, $nixpacks_php_root_dir];
    }

    private function rolling_update()
    {
        if ($this->server->isSwarm()) {
            $this->application_deployment_queue->addLogEntry('Rolling update started.');
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "docker stack deploy --with-registry-auth -c {$this->workdir}{$this->docker_compose_location} {$this->application->uuid}"),
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
    }

    private function health_check()
    {
        if ($this->server->isSwarm()) {
            // Implement healthcheck for swarm
        } else {
            if ($this->application->isHealthcheckDisabled() && $this->application->custom_healthcheck_found === false) {
                $this->newVersionIsHealthy = true;

                return;
            }
            if ($this->application->custom_healthcheck_found) {
                $this->application_deployment_queue->addLogEntry('Custom healthcheck found, skipping default healthcheck.');
            }
            // ray('New container name: ', $this->container_name);
            if ($this->container_name) {
                $counter = 1;
                $this->application_deployment_queue->addLogEntry('Waiting for healthcheck to pass on the new container.');
                if ($this->full_healthcheck_url) {
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
                    }
                    if (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'unhealthy') {
                        $this->newVersionIsHealthy = false;
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
        $this->generate_build_env_variables();
        if ($this->application->build_pack === 'dockerfile') {
            $this->add_build_env_variables_to_dockerfile();
        }
        $this->build_image();
        $this->push_to_docker_registry();
        // $this->stop_running_container();
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

    private function prepare_builder_image()
    {
        $settings = InstanceSettings::get();
        $helperImage = config('coolify.helper_image');
        $helperImage = "{$helperImage}:{$settings->helper_version}";
        // Get user home directory
        $this->serverUserHomeDir = instant_remote_process(['echo $HOME'], $this->server);
        $this->dockerConfigFileExists = instant_remote_process(["test -f {$this->serverUserHomeDir}/.docker/config.json && echo 'OK' || echo 'NOK'"], $this->server);
        if ($this->use_build_server) {
            if ($this->dockerConfigFileExists === 'NOK') {
                throw new RuntimeException('Docker config file (~/.docker/config.json) not found on the build server. Please run "docker login" to login to the docker registry on the server.');
            }
            $runCommand = "docker run -d --name {$this->deployment_uuid} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($this->dockerConfigFileExists === 'OK') {
                $runCommand = "docker run -d --network {$this->destination->network} --name {$this->deployment_uuid} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $runCommand = "docker run -d --network {$this->destination->network} --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }
        $this->application_deployment_queue->addLogEntry("Preparing container with helper image: $helperImage.");
        $this->execute_remote_command(
            [
                'command' => "docker rm -f {$this->deployment_uuid}",
                'ignore_errors' => true,
                'hidden' => true,
            ]
        );
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
            ray('Same destination found in additional destinations. Skipping.');

            return;
        }
        foreach ($destination_ids as $destination_id) {
            $destination = StandaloneDocker::find($destination_id);
            $server = $destination->server;
            if ($server->team_id !== $this->mainServer->team_id) {
                $this->application_deployment_queue->addLogEntry("Skipping deployment to {$server->name}. Not in the same team?!");

                continue;
            }
            // ray('Deploying to additional destination: ', $server->name);
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
                'environment_name' => data_get($this->application, 'environment.name'),
            ]));
        }
    }

    private function set_coolify_variables()
    {
        $this->coolify_variables = "SOURCE_COMMIT={$this->commit} ";
        if ($this->pull_request_id === 0) {
            $fqdn = $this->application->fqdn;
        } else {
            $fqdn = $this->preview->fqdn;
        }
        if (isset($fqdn)) {
            $this->coolify_variables .= "COOLIFY_FQDN={$fqdn} ";
            $url = str($fqdn)->replace('http://', '')->replace('https://', '');
            $this->coolify_variables .= "COOLIFY_URL={$url} ";
        }
        if (isset($this->application->git_branch)) {
            $this->coolify_variables .= "COOLIFY_BRANCH={$this->application->git_branch} ";
        }
    }

    private function check_git_if_build_needed()
    {
        $this->generate_git_import_commands();
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            $local_branch = "pull/{$this->pull_request_id}/head";
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
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git ls-remote {$this->fullRepoUrl} {$local_branch}"),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ],
            );
        } else {
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$this->fullRepoUrl} {$local_branch}"),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ],
            );
        }
        if ($this->saved_outputs->get('git_commit_sha') && ! $this->rollback) {
            $this->commit = $this->saved_outputs->get('git_commit_sha')->before("\t");
            $this->application_deployment_queue->commit = $this->commit;
            $this->application_deployment_queue->save();
        }
        $this->set_coolify_variables();
    }

    private function clone_repository()
    {
        $importCommands = $this->generate_git_import_commands();
        $this->application_deployment_queue->addLogEntry("\n----------------------------------------");
        $this->application_deployment_queue->addLogEntry("Importing {$this->customRepository}:{$this->application->git_branch} (commit sha {$this->application->git_commit_sha}) to {$this->basedir}.");
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
            $commit_message = str($this->saved_outputs->get('commit_message'))->limit(47);
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
                throw new RuntimeException('Nixpacks failed to detect the application type. Please check the documentation of Nixpacks: https://nixpacks.com/docs/providers');
            }
        }

        if ($this->saved_outputs->get('nixpacks_plan')) {
            $this->nixpacks_plan = $this->saved_outputs->get('nixpacks_plan');
            if ($this->nixpacks_plan) {
                $this->application_deployment_queue->addLogEntry("Found application type: {$this->nixpacks_type}.");
                $this->application_deployment_queue->addLogEntry("If you need further customization, please check the documentation of Nixpacks: https://nixpacks.com/docs/providers/{$this->nixpacks_type}");
                $parsed = Toml::Parse($this->nixpacks_plan);

                // Do any modifications here
                $this->generate_env_variables();
                $merged_envs = $this->env_args->merge(collect(data_get($parsed, 'variables', [])));
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
                $this->nixpacks_plan = json_encode($parsed, JSON_PRETTY_PRINT);
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
        $nixpacks_command = "nixpacks plan -f toml {$this->env_nixpacks_args}";
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
                if (! is_null($env->real_value)) {
                    $this->env_nixpacks_args->push("--env {$env->key}={$env->real_value}");
                }
            }
        } else {
            foreach ($this->application->nixpacks_environment_variables_preview as $env) {
                if (! is_null($env->real_value)) {
                    $this->env_nixpacks_args->push("--env {$env->key}={$env->real_value}");
                }
            }
        }

        $this->env_nixpacks_args = $this->env_nixpacks_args->implode(' ');
    }

    private function generate_env_variables()
    {
        $this->env_args = collect([]);
        $this->env_args->put('SOURCE_COMMIT', $this->commit);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->build_environment_variables as $env) {
                if (! is_null($env->real_value)) {
                    $this->env_args->put($env->key, $env->real_value);
                }
            }
        } else {
            foreach ($this->application->build_environment_variables_preview as $env) {
                if (! is_null($env->real_value)) {
                    $this->env_args->put($env->key, $env->real_value);
                }
            }
        }
    }

    private function generate_compose_file()
    {
        $this->create_workdir();
        $ports = $this->application->main_port();
        $onlyPort = null;
        if (count($ports) > 0) {
            $onlyPort = $ports[0];
        }
        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->application->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        // $environment_variables = $this->generate_environment_variables($ports);
        $this->save_environment_variables();
        if (data_get($this->application, 'custom_labels')) {
            $this->application->parseContainerLabels();
            $labels = collect(preg_split("/\r\n|\n|\r/", base64_decode($this->application->custom_labels)));
            $labels = $labels->filter(function ($value, $key) {
                return ! Str::startsWith($value, 'coolify.');
            });
            $found_caddy_labels = $labels->filter(function ($value, $key) {
                return Str::startsWith($value, 'caddy_');
            });
            if ($found_caddy_labels->count() === 0) {
                if ($this->pull_request_id !== 0) {
                    $domains = str(data_get($this->preview, 'fqdn'))->explode(',');
                } else {
                    $domains = str(data_get($this->application, 'fqdn'))->explode(',');
                }
                $labels = $labels->merge(fqdnLabelsForCaddy(
                    network: $this->application->destination->network,
                    uuid: $this->application->uuid,
                    domains: $domains,
                    onlyPort: $onlyPort,
                    is_force_https_enabled: $this->application->isForceHttpsEnabled(),
                    is_gzip_enabled: $this->application->isGzipEnabled(),
                    is_stripprefix_enabled: $this->application->isStripprefixEnabled()
                ));
            }
            $this->application->custom_labels = base64_encode($labels->implode("\n"));
            $this->application->save();
        } else {
            if (! $this->application->settings->is_container_label_readonly_enabled) {
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
        $labels = $labels->merge(defaultLabels($this->application->id, $this->application->uuid, $this->pull_request_id))->toArray();

        // Check for custom HEALTHCHECK
        if ($this->application->build_pack === 'dockerfile' || $this->application->dockerfile) {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
                'hidden' => true,
                'save' => 'dockerfile_from_repo',
                'ignore_errors' => true,
            ]);
            $dockerfile = collect(str($this->saved_outputs->get('dockerfile_from_repo'))->trim()->explode("\n"));
            $this->application->parseHealthcheckFromDockerfile($dockerfile);
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
                            'aliases' => [
                                $this->container_name,
                            ],
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
        if (! is_null($this->env_filename)) {
            $docker_compose['services'][$this->container_name]['env_file'] = [$this->env_filename];
        }
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
            $custom_compose = convert_docker_run_to_compose($this->application->custom_docker_run_options);
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
                $volume_name = $volume_name.'-pr-'.$this->pull_request_id;
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
                $name = $name.'-pr-'.$this->pull_request_id;
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

    private function build_image()
    {
        // Add Coolify related variables to the build args
        $this->environment_variables->filter(function ($key, $value) {
            return str($key)->startsWith('COOLIFY_');
        })->each(function ($key, $value) {
            $this->build_args->push("--build-arg '{$key}'");
        });

        $this->build_args = $this->build_args->implode(' ');

        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        if ($this->application->build_pack === 'static') {
            $this->application_deployment_queue->addLogEntry('Static deployment. Copying static assets to the image.');
        } else {
            $this->application_deployment_queue->addLogEntry('Building docker image started.');
            $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');
        }

        if ($this->application->settings->is_static || $this->application->build_pack === 'static') {
            if ($this->application->static_image) {
                $this->pull_latest_image($this->application->static_image);
                $this->application_deployment_queue->addLogEntry('Continuing with the building process.');
            }
            if ($this->application->build_pack === 'static') {
                $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY . .
RUN rm -f /usr/share/nginx/html/nginx.conf
RUN rm -f /usr/share/nginx/html/Dockerfile
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");
                $nginx_config = base64_encode('server {
                listen       80;
                listen  [::]:80;
                server_name  localhost;

                location / {
                    root   /usr/share/nginx/html;
                    index  index.html;
                    try_files $uri $uri.html $uri/index.html $uri/ /index.html =404;
                }

                error_page   500 502 503 504  /50x.html;
                location = /50x.html {
                    root   /usr/share/nginx/html;
                }
            }');
            } else {
                if ($this->application->build_pack === 'nixpacks') {
                    $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee /artifacts/thegameplan.json > /dev/null"), 'hidden' => true]);
                    if ($this->force_rebuild) {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --no-cache --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ]);
                        $build_command = "docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile {$this->build_args} --progress plain -t {$this->build_image_name} {$this->workdir}";
                    } else {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ]);
                        $build_command = "docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile {$this->build_args} --progress plain -t {$this->build_image_name} {$this->workdir}";
                    }

                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                            'hidden' => true,
                        ]
                    );
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm /artifacts/thegameplan.json'), 'hidden' => true]);
                } else {
                    if ($this->force_rebuild) {
                        $build_command = "docker build --no-cache {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->build_image_name {$this->workdir}";
                        $base64_build_command = base64_encode($build_command);
                    } else {
                        $build_command = "docker build {$this->buildTarget} --network {$this->destination->network} -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->build_image_name {$this->workdir}";
                        $base64_build_command = base64_encode($build_command);
                    }
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                            'hidden' => true,
                        ]
                    );
                }
                $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from=$this->build_image_name /app/{$this->application->publish_directory} .
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");

                $nginx_config = base64_encode('server {
                listen       80;
                listen  [::]:80;
                server_name  localhost;

                location / {
                    root   /usr/share/nginx/html;
                    index  index.html;
                    try_files $uri $uri.html $uri/index.html $uri/ /index.html =404;
                }

                error_page   500 502 503 504  /50x.html;
                location = /50x.html {
                    root   /usr/share/nginx/html;
                }
            }');
            }
            $build_command = "docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
            $base64_build_command = base64_encode($build_command);
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                    'hidden' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                    'hidden' => true,
                ]
            );
        } else {
            // Pure Dockerfile based deployment
            if ($this->application->dockerfile) {
                if ($this->force_rebuild) {
                    $build_command = "docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                } else {
                    $build_command = "docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                }
                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                        'hidden' => true,
                    ]
                );
            } else {
                if ($this->application->build_pack === 'nixpacks') {
                    $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee /artifacts/thegameplan.json > /dev/null"), 'hidden' => true]);
                    if ($this->force_rebuild) {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --no-cache --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ]);
                        $build_command = "docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    } else {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, "nixpacks build -c /artifacts/thegameplan.json --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ]);
                        $build_command = "docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    }
                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                            'hidden' => true,
                        ]
                    );
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm /artifacts/thegameplan.json'), 'hidden' => true]);
                } else {
                    if ($this->force_rebuild) {
                        $build_command = "docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                        $base64_build_command = base64_encode($build_command);
                    } else {
                        $build_command = "docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}";
                        $base64_build_command = base64_encode($build_command);
                    }
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee /artifacts/build.sh > /dev/null"),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash /artifacts/build.sh'),
                            'hidden' => true,
                        ]
                    );
                }
            }
        }
        $this->application_deployment_queue->addLogEntry('Building docker image completed.');
    }

    /**
     * @param  int  $timeout  in seconds
     */
    private function graceful_shutdown_container(string $containerName, int $timeout = 30)
    {
        try {
            $this->execute_remote_command(
                ["docker stop --time=$timeout $containerName", 'hidden' => true, 'ignore_errors' => true],
                ["docker rm $containerName", 'hidden' => true, 'ignore_errors' => true]
            );
        } catch (\Exception $error) {
            // report error if needed
        }

        $this->execute_remote_command(
            ["docker rm -f $containerName", 'hidden' => true, 'ignore_errors' => true]
        );
    }

    private function stop_running_container(bool $force = false)
    {
        $this->application_deployment_queue->addLogEntry('Removing old containers.');
        if ($this->newVersionIsHealthy || $force) {
            if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                $this->graceful_shutdown_container($this->container_name);
            } else {
                $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
                if ($this->pull_request_id === 0) {
                    $containers = $containers->filter(function ($container) {
                        return data_get($container, 'Names') !== $this->container_name && data_get($container, 'Names') !== $this->container_name.'-pr-'.$this->pull_request_id;
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
            $this->application_deployment_queue->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
            ]);
            $this->graceful_shutdown_container($this->container_name);
        }
    }

    private function start_by_compose_file()
    {
        if ($this->application->build_pack === 'dockerimage') {
            $this->application_deployment_queue->addLogEntry('Pulling latest images from the registry.');
            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, "docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} pull"), 'hidden' => true],
                [executeInDocker($this->deployment_uuid, "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} up --build -d"), 'hidden' => true],
            );
        } else {
            if ($this->use_build_server) {
                $this->execute_remote_command(
                    ["{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->configuration_dir} -f {$this->configuration_dir}{$this->docker_compose_location} up --build -d", 'hidden' => true],
                );
            } else {
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} up --build -d"), 'hidden' => true],
                );
            }
        }
        $this->application_deployment_queue->addLogEntry('New container started.');
    }

    private function generate_build_env_variables()
    {
        $this->build_args = collect(["--build-arg SOURCE_COMMIT=\"{$this->commit}\""]);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->build_environment_variables as $env) {
                $value = escapeshellarg($env->real_value);
                $this->build_args->push("--build-arg {$env->key}={$value}");
            }
        } else {
            foreach ($this->application->build_environment_variables_preview as $env) {
                $value = escapeshellarg($env->real_value);
                $this->build_args->push("--build-arg {$env->key}={$value}");
            }
        }
    }

    private function add_build_env_variables_to_dockerfile()
    {
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
            'hidden' => true,
            'save' => 'dockerfile',
        ]);
        $dockerfile = collect(str($this->saved_outputs->get('dockerfile'))->trim()->explode("\n"));
        if ($this->pull_request_id === 0) {
            foreach ($this->application->build_environment_variables as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $dockerfile->splice(1, 0, "ARG {$env->key}");
                } else {
                    $dockerfile->splice(1, 0, "ARG {$env->key}={$env->real_value}");
                }
            }
        } else {
            foreach ($this->application->build_environment_variables_preview as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $dockerfile->splice(1, 0, "ARG {$env->key}");
                } else {
                    $dockerfile->splice(1, 0, "ARG {$env->key}={$env->real_value}");
                }
            }
        }
        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$this->workdir}{$this->dockerfile_location} > /dev/null"),
            'hidden' => true,
        ]);
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
        throw new RuntimeException('Pre-deployment command: Could not find a valid container. Is the container name correct?');
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
        throw new RuntimeException('Post-deployment command: Could not find a valid container. Is the container name correct?');
    }

    private function next(string $status)
    {
        queue_next_deployment($this->application);
        // If the deployment is cancelled by the user, don't update the status
        if (
            $this->application_deployment_queue->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value && $this->application_deployment_queue->status !== ApplicationDeploymentStatus::FAILED->value
        ) {
            $this->application_deployment_queue->update([
                'status' => $status,
            ]);
        }
        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FAILED->value) {
            $this->application->environment->project->team?->notify(new DeploymentFailed($this->application, $this->deployment_uuid, $this->preview));

            return;
        }
        if ($status === ApplicationDeploymentStatus::FINISHED->value) {
            if (! $this->only_this_server) {
                $this->deploy_to_additional_destinations();
            }
            $this->application->environment->project->team?->notify(new DeploymentSuccess($this->application, $this->deployment_uuid, $this->preview));
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->next(ApplicationDeploymentStatus::FAILED->value);
        $this->application_deployment_queue->addLogEntry('Oops something is not okay, are you okay? 😢', 'stderr');
        if (str($exception->getMessage())->isNotEmpty()) {
            $this->application_deployment_queue->addLogEntry($exception->getMessage(), 'stderr');
        }

        if ($this->application->build_pack !== 'dockercompose') {
            $code = $exception->getCode();
            ray($code);
            if ($code !== 69420) {
                // 69420 means failed to push the image to the registry, so we don't need to remove the new version as it is the currently running one
                if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                    // do not remove already running container
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
