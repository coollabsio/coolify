<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
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
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecuteRemoteCommand;

    public static int $batch_counter = 0;

    private int $application_deployment_queue_id;

    private bool $newVersionIsHealthy = false;
    private ApplicationDeploymentQueue $application_deployment_queue;
    private Application $application;
    private string $deployment_uuid;
    private int $pull_request_id;
    private string $commit;
    private bool $force_rebuild;

    private ?string $dockerImage = null;
    private ?string $dockerImageTag = null;

    private GithubApp|GitlabApp|string $source = 'other';
    private StandaloneDocker|SwarmDocker $destination;
    private Server $server;
    private ApplicationPreview|null $preview = null;

    private string $container_name;
    private ?string $currently_running_container_name = null;
    private string $basedir;
    private string $workdir;
    private ?string $build_pack = null;
    private string $configuration_dir;
    private string $build_image_name;
    private string $production_image_name;
    private bool $is_debug_enabled;
    private $build_args;
    private $env_args;
    private $docker_compose;
    private $docker_compose_base64;
    private string $dockerfile_location = '/Dockerfile';
    private ?string $addHosts = null;
    private $log_model;
    private Collection $saved_outputs;

    private string $serverUser = 'root';
    private string $serverUserHomeDir = '/root';
    private string $dockerConfigFileExists = 'NOK';

    private int $customPort = 22;

    private ?string $fullRepoUrl = null;
    private ?string $branch = null;

    public $tries = 1;
    public function __construct(int $application_deployment_queue_id)
    {
        // ray()->clearScreen();
        $this->application_deployment_queue = ApplicationDeploymentQueue::find($application_deployment_queue_id);
        $this->log_model = $this->application_deployment_queue;
        $this->application = Application::find($this->application_deployment_queue->application_id);
        $this->build_pack = data_get($this->application, 'build_pack');

        $this->application_deployment_queue_id = $application_deployment_queue_id;
        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;

        $source = data_get($this->application, 'source');
        if ($source) {
            $this->source = $source->getMorphClass()::where('id', $this->application->source->id)->first();
        }
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
        $this->server = $this->destination->server;
        $this->serverUser = $this->server->user;
        $this->basedir = "/artifacts/{$this->deployment_uuid}";
        $this->workdir = "{$this->basedir}" . rtrim($this->application->base_directory, '/');
        $this->configuration_dir = application_configuration_dir() . "/{$this->application->uuid}";
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;

        $this->container_name = generateApplicationContainerName($this->application, $this->pull_request_id);
        savePrivateKeyToFs($this->server);
        $this->saved_outputs = collect();

        // Set preview fqdn
        if ($this->pull_request_id !== 0) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
            if ($this->application->fqdn) {
                if (data_get($this->preview, 'fqdn')) {
                    $preview_fqdn = getFqdnWithoutPort(data_get($this->preview, 'fqdn'));
                }
                $template = $this->application->preview_url_template;
                $url = Url::fromString($this->application->fqdn);
                $host = $url->getHost();
                $schema = $url->getScheme();
                $random = new Cuid2(7);
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn";
                $this->preview->fqdn = $preview_fqdn;
                $this->preview->save();
            }
        }
    }

    public function handle(): void
    {
        // ray()->measure();
        $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id);
        if ($containers->count() > 0) {
            $this->currently_running_container_name = data_get($containers[0], 'Names');
        }
        if ($this->pull_request_id !== 0 && $this->pull_request_id !== null) {
            $this->currently_running_container_name = $this->container_name;
        }
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        // Generate custom host<->ip mapping
        $allContainers = instant_remote_process(["docker network inspect {$this->destination->network} -f '{{json .Containers}}' "], $this->server);
        $allContainers = format_docker_command_output_to_json($allContainers);
        $ips = collect([]);
        if (count($allContainers) > 0) {
            $allContainers = $allContainers[0];
            foreach ($allContainers as $container) {
                $containerName = data_get($container, 'Name');
                if ($containerName === 'coolify-proxy') {
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

        // Get user home directory
        $this->serverUserHomeDir = instant_remote_process(["echo \$HOME"], $this->server);
        $this->dockerConfigFileExists = instant_remote_process(["test -f {$this->serverUserHomeDir}/.docker/config.json && echo 'OK' || echo 'NOK'"], $this->server);

        // Check custom port
        preg_match('/(?<=:)\d+(?=\/)/', $this->application->git_repository, $matches);
        if (count($matches) === 1) {
            $this->customPort = $matches[0];
            $gitHost = str($this->application->git_repository)->before(':');
            $gitRepo = str($this->application->git_repository)->after('/');
            $this->application->git_repository = "$gitHost:$gitRepo";
        }
        try {
            if ($this->application->dockerfile) {
                $this->deploy_simple_dockerfile();
            } else if ($this->application->build_pack === 'dockerimage') {
                $this->deploy_dockerimage_buildpack();
            } else if ($this->application->build_pack === 'dockerfile') {
                $this->deploy_dockerfile_buildpack();
            } else {
                if ($this->pull_request_id !== 0) {
                    $this->deploy_pull_request();
                } else {
                    $this->deploy_nixpacks_buildpack();
                }
            }
            if ($this->server->isProxyShouldRun()) {
                dispatch(new ContainerStatusJob($this->server));
            }
            $this->next(ApplicationDeploymentStatus::FINISHED->value);
            $this->application->isConfigurationChanged(true);
        } catch (Exception $e) {
            ray($e);
            $this->fail($e);
            throw $e;
        } finally {
            if (isset($this->docker_compose_base64)) {
                $readme = generate_readme_file($this->application->name, $this->application_deployment_queue->updated_at);
                $this->execute_remote_command(
                    [
                        "mkdir -p $this->configuration_dir"
                    ],
                    [
                        "echo '{$this->docker_compose_base64}' | base64 -d > $this->configuration_dir/docker-compose.yml",
                    ],
                    [
                        "echo '{$readme}' > $this->configuration_dir/README.md",
                    ]
                );
            }
            $this->execute_remote_command(
                [
                    "docker rm -f {$this->deployment_uuid} >/dev/null 2>&1",
                    "hidden" => true,
                ]
            );
        }
    }

    // private function deploy_docker_compose()
    // {
    //     $dockercompose_base64 = base64_encode($this->application->dockercompose);
    //     $this->execute_remote_command(
    //         [
    //             "echo 'Starting deployment of {$this->application->name}.'"
    //         ],
    //     );
    //     $this->prepare_builder_image();
    //     $this->execute_remote_command(
    //         [
    //             executeInDocker($this->deployment_uuid, "echo '$dockercompose_base64' | base64 -d > $this->workdir/docker-compose.yaml")
    //         ],
    //     );
    //     $this->build_image_name = Str::lower("{$this->application->git_repository}:build");
    //     $this->production_image_name = Str::lower("{$this->application->uuid}:latest");
    //     $this->save_environment_variables();
    //     $containers = getCurrentApplicationContainerStatus($this->application->destination->server, $this->application->id);
    //     ray($containers);
    //     if ($containers->count() > 0) {
    //         foreach ($containers as $container) {
    //             $containerName = data_get($container, 'Names');
    //             if ($containerName) {
    //                 instant_remote_process(
    //                     ["docker rm -f {$containerName}"],
    //                     $this->application->destination->server
    //                 );
    //             }
    //         }
    //     }

    //     $this->execute_remote_command(
    //         ["echo -n 'Starting services (could take a while)...'"],
    //         [executeInDocker($this->deployment_uuid, "docker compose --project-directory {$this->workdir} up -d"), "hidden" => true],
    //     );
    // }
    private function save_environment_variables()
    {
        $envs = collect([]);
        foreach ($this->application->environment_variables as $env) {
            $envs->push($env->key . '=' . $env->value);
        }
        $envs_base64 = base64_encode($envs->implode("\n"));
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d > $this->workdir/.env")
            ],
        );
    }
    private function deploy_simple_dockerfile()
    {
        $dockerfile_base64 = base64_encode($this->application->dockerfile);
        $this->execute_remote_command(
            [
                "echo 'Starting deployment of {$this->application->name}.'"
            ],
        );
        $this->prepare_builder_image();
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$dockerfile_base64' | base64 -d > $this->workdir/Dockerfile")
            ],
        );
        $this->build_image_name = Str::lower("{$this->application->git_repository}:build");
        $this->production_image_name = Str::lower("{$this->application->uuid}:latest");
        // ray('Build Image Name: ' . $this->build_image_name . ' & Production Image Name: ' . $this->production_image_name)->green();
        $this->generate_compose_file();
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->rolling_update();
    }

    private function deploy_dockerimage_buildpack()
    {
        $this->dockerImage = $this->application->docker_registry_image_name;
        $this->dockerImageTag = $this->application->docker_registry_image_tag;
        ray("echo 'Starting deployment of {$this->dockerImage}:{$this->dockerImageTag}.'");
        $this->execute_remote_command(
            [
                "echo 'Starting deployment of {$this->dockerImage}:{$this->dockerImageTag}.'"
            ],
        );
        $this->production_image_name = Str::lower("{$this->dockerImage}:{$this->dockerImageTag}");
        $this->prepare_builder_image();
        $this->generate_compose_file();
        $this->rolling_update();
    }

    private function deploy_dockerfile_buildpack()
    {
        if (data_get($this->application, 'dockerfile_location')) {
            $this->dockerfile_location = $this->application->dockerfile_location;
        }
        $this->execute_remote_command(
            [
                "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}.'"
            ],
        );
        $this->prepare_builder_image();
        $this->clone_repository();
        $this->set_base_dir();
        $tag = Str::of("{$this->commit}-{$this->application->id}-{$this->pull_request_id}");
        if (strlen($tag) > 128) {
            $tag = $tag->substr(0, 128);
        }

        $this->build_image_name = Str::lower("{$this->application->git_repository}:{$tag}-build");
        $this->production_image_name = Str::lower("{$this->application->uuid}:{$tag}");
        // ray('Build Image Name: ' . $this->build_image_name . ' & Production Image Name: ' . $this->production_image_name)->green();
        $this->cleanup_git();
        $this->generate_compose_file();
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->rolling_update();
    }
    private function deploy_nixpacks_buildpack()
    {
        $this->execute_remote_command(
            [
                "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}.'"
            ],
        );
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->set_base_dir();
        $tag = Str::of("{$this->commit}-{$this->application->id}-{$this->pull_request_id}");
        if (strlen($tag) > 128) {
            $tag = $tag->substr(0, 128);
        }

        $this->build_image_name = Str::lower("{$this->application->git_repository}:{$tag}-build");
        $this->production_image_name = Str::lower("{$this->application->uuid}:{$tag}");
        // ray('Build Image Name: ' . $this->build_image_name . ' & Production Image Name: ' . $this->production_image_name)->green();

        if (!$this->force_rebuild) {
            $this->execute_remote_command([
                "docker images -q {$this->production_image_name} 2>/dev/null", "hidden" => true, "save" => "local_image_found"
            ]);
            if (Str::of($this->saved_outputs->get('local_image_found'))->isNotEmpty() && !$this->application->isConfigurationChanged()) {
                $this->execute_remote_command([
                    "echo 'No configuration changed & Docker Image found locally with the same Git Commit SHA {$this->application->uuid}:{$this->commit}. Build step skipped.'",
                ]);
                $this->generate_compose_file();
                $this->rolling_update();
                return;
            }
            if ($this->application->isConfigurationChanged()) {
                $this->execute_remote_command([
                    "echo 'Configuration changed. Rebuilding image.'",
                ]);
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_nixpacks_confs();
        $this->generate_compose_file();
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->rolling_update();
    }

    private function rolling_update()
    {
        if (count($this->application->ports_mappings_array) > 0) {
            $this->execute_remote_command(
                ["echo -n 'Application has ports mapped to the host system, rolling update is not supported. Stopping current container.'"],
            );
            $this->stop_running_container(force: true);
            $this->start_by_compose_file();
        } else {
            $this->execute_remote_command(
                ["echo -n 'Rolling update started.'"],
            );
            $this->start_by_compose_file();
            $this->health_check();
            $this->stop_running_container();
        }
    }
    private function health_check()
    {
        if ($this->application->isHealthcheckDisabled()) {
            $this->newVersionIsHealthy = true;
            return;
        }
        // ray('New container name: ', $this->container_name);
        if ($this->container_name) {
            $counter = 0;
            $this->execute_remote_command(
                [
                    "echo 'Waiting for healthcheck to pass on the new version of your application.'"
                ],
            );
            while ($counter < $this->application->health_check_retries) {
                $this->execute_remote_command(
                    [
                        "echo 'Attempt {$counter} of {$this->application->health_check_retries}'"
                    ],
                    [
                        "docker inspect --format='{{json .State.Health.Status}}' {$this->container_name}",
                        "hidden" => true,
                        "save" => "health_check"
                    ],

                );
                $this->execute_remote_command(
                    [
                        "echo 'New version healthcheck status: {$this->saved_outputs->get('health_check')}'"
                    ],
                );
                if (Str::of($this->saved_outputs->get('health_check'))->contains('healthy')) {
                    $this->newVersionIsHealthy = true;
                    $this->execute_remote_command(
                        [
                            "echo 'Rolling update completed.'"
                        ],
                    );
                    $this->application->update(['status' => 'running']);
                    break;
                }
                $counter++;
                sleep($this->application->health_check_interval);
            }
        }
    }
    private function deploy_pull_request()
    {
        $this->build_image_name = Str::lower("{$this->application->uuid}:pr-{$this->pull_request_id}-build");
        $this->production_image_name = Str::lower("{$this->application->uuid}:pr-{$this->pull_request_id}");
        // ray('Build Image Name: ' . $this->build_image_name . ' & Production Image Name: ' . $this->production_image_name)->green();
        $this->execute_remote_command([
            "echo 'Starting pull request (#{$this->pull_request_id}) deployment of {$this->application->git_repository}:{$this->application->git_branch}.'",
        ]);
        $this->prepare_builder_image();
        $this->clone_repository();
        $this->set_base_dir();
        $this->cleanup_git();
        if ($this->application->build_pack === 'nixpacks') {
            $this->generate_nixpacks_confs();
        }
        $this->generate_compose_file();
        // Needs separate preview variables
        // $this->generate_build_env_variables();
        // $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->stop_running_container();
        $this->execute_remote_command(
            ["echo -n 'Starting preview deployment.'"],
            [executeInDocker($this->deployment_uuid, "docker compose --project-directory {$this->workdir} up -d"), "hidden" => true],
        );
    }

    private function prepare_builder_image()
    {
        $helperImage = config('coolify.helper_image');
        if ($this->dockerConfigFileExists === 'OK') {
            $runCommand = "docker run -d --network {$this->destination->network} -v /:/host --name {$this->deployment_uuid} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            $runCommand = "docker run -d --network {$this->destination->network} -v /:/host --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        }

        $this->execute_remote_command(
            [
                "echo -n 'Preparing container with helper image: $helperImage.'",
            ],
            [
                $runCommand,
                "hidden" => true,
            ],
            [
                "command" => executeInDocker($this->deployment_uuid, "mkdir -p {$this->basedir}")
            ],
        );
    }

    private function set_base_dir()
    {
        $this->execute_remote_command(
            [
                "echo -n 'Setting base directory to {$this->workdir}.'"
            ],
        );
    }
    private function check_git_if_build_needed()
    {
        $this->generate_git_import_commands();
        $private_key = data_get($this->application, 'private_key.private_key');
        if ($private_key) {
            $private_key = base64_encode($private_key);
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "mkdir -p /root/.ssh")
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa")
                ],
                [
                    executeInDocker($this->deployment_uuid, "chmod 600 /root/.ssh/id_rsa")
                ],
                [
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git ls-remote {$this->fullRepoUrl} {$this->branch}"),
                    "hidden" => true,
                    "save" => "git_commit_sha"
                ],
            );
        } else {
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git ls-remote {$this->fullRepoUrl} {$this->branch}"),
                    "hidden" => true,
                    "save" => "git_commit_sha"
                ],
            );
        }

        $this->commit = $this->saved_outputs->get('git_commit_sha')->before("\t");
    }
    private function clone_repository()
    {
        $importCommands = $this->generate_git_import_commands();
        $this->execute_remote_command(
            [
                "echo -n 'Importing {$this->application->git_repository}:{$this->application->git_branch} (commit sha {$this->application->git_commit_sha}) to {$this->basedir}. '"
            ],
            [
                $importCommands, "hidden" => true
            ]
        );
    }

    private function generate_git_import_commands()
    {
        $this->branch = $this->application->git_branch;
        $commands = collect([]);
        $git_clone_command = "git clone -q -b {$this->application->git_branch}";
        if ($this->pull_request_id !== 0) {
            $pr_branch_name = "pr-{$this->pull_request_id}-coolify";
        }

        if ($this->application->deploymentType() === 'source') {
            $source_html_url = data_get($this->application, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                if ($this->source->is_public) {
                    $this->fullRepoUrl = "{$this->source->html_url}/{$this->application->git_repository}";
                    $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$this->application->git_repository} {$this->basedir}";
                    $git_clone_command = $this->set_git_import_settings($git_clone_command);

                    $commands->push(executeInDocker($this->deployment_uuid, $git_clone_command));
                } else {
                    $github_access_token = generate_github_installation_token($this->source);
                    $commands->push(executeInDocker($this->deployment_uuid, "git clone -q -b {$this->application->git_branch} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git {$this->basedir}"));
                    $this->fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git";
                }
                if ($this->pull_request_id !== 0) {
                    $this->branch = "pull/{$this->pull_request_id}/head:$pr_branch_name";
                    $commands->push(executeInDocker($this->deployment_uuid, "cd {$this->basedir} && git fetch origin pull/{$this->pull_request_id}/head:$pr_branch_name && git checkout $pr_branch_name"));
                }
                return $commands->implode(' && ');
            }
        }
        if ($this->application->deploymentType() === 'deploy_key') {
            $this->fullRepoUrl = $this->application->git_repository;
            $private_key = base64_encode($this->application->private_key->private_key);
            $git_clone_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$git_clone_command} {$this->application->git_repository} {$this->basedir}";
            $git_clone_command = $this->set_git_import_settings($git_clone_command);
            $commands = collect([
                executeInDocker($this->deployment_uuid, "mkdir -p /root/.ssh"),
                executeInDocker($this->deployment_uuid, "echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa"),
                executeInDocker($this->deployment_uuid, "chmod 600 /root/.ssh/id_rsa"),
                executeInDocker($this->deployment_uuid, $git_clone_command)
            ]);
            return $commands->implode(' && ');
        }
        if ($this->application->deploymentType() === 'other') {
            $this->fullRepoUrl = $this->application->git_repository;
            $git_clone_command = "{$git_clone_command} {$this->application->git_repository} {$this->basedir}";
            $git_clone_command = $this->set_git_import_settings($git_clone_command);
            $commands->push(executeInDocker($this->deployment_uuid, $git_clone_command));
            return $commands->implode(' && ');
        }
    }

    private function set_git_import_settings($git_clone_command)
    {
        if ($this->application->git_commit_sha !== 'HEAD') {
            $git_clone_command = "{$git_clone_command} && cd {$this->basedir} && git -c advice.detachedHead=false checkout {$this->application->git_commit_sha} >/dev/null 2>&1";
        }
        if ($this->application->settings->is_git_submodules_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$this->basedir} && git submodule update --init --recursive";
        }
        if ($this->application->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$this->basedir} && git lfs pull";
        }
        return $git_clone_command;
    }

    private function cleanup_git()
    {
        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, "rm -fr {$this->basedir}/.git")],
        );
    }

    private function generate_nixpacks_confs()
    {

        $this->execute_remote_command(
            [
                "echo -n 'Generating nixpacks configuration.'",
            ]
        );
        $nixpacks_command = $this->nixpacks_build_cmd();
        $this->execute_remote_command(
            [
                "echo -n Running: $nixpacks_command",
            ],
            [executeInDocker($this->deployment_uuid, $nixpacks_command)],
            [executeInDocker($this->deployment_uuid, "cp {$this->workdir}/.nixpacks/Dockerfile {$this->workdir}/Dockerfile")],
            [executeInDocker($this->deployment_uuid, "rm -f {$this->workdir}/.nixpacks/Dockerfile")]
        );
    }

    private function nixpacks_build_cmd()
    {
        $this->generate_env_variables();
        $nixpacks_command = "nixpacks build --no-cache -o {$this->workdir} {$this->env_args} --no-error-without-start";
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

    private function generate_env_variables()
    {
        $this->env_args = collect([]);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->nixpacks_environment_variables as $env) {
                $this->env_args->push("--env {$env->key}={$env->value}");
            }
        } else {
            foreach ($this->application->nixpacks_environment_variables_preview as $env) {
                $this->env_args->push("--env {$env->key}={$env->value}");
            }
        }

        $this->env_args = $this->env_args->implode(' ');
    }

    private function generate_compose_file()
    {
        $ports = $this->application->settings->is_static ? [80] : $this->application->ports_exposes_array;

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables($ports);

        if (data_get($this->application, 'custom_labels')) {
            $labels = collect(str($this->application->custom_labels)->explode(','));
            $labels = $labels->filter(function ($value, $key) {
                return !Str::startsWith($value, 'coolify.');
            });
            $this->application->custom_labels = $labels->implode(',');
            $this->application->save();
        } else {
            $labels = collect(generateLabelsApplication($this->application, $this->preview));
        }
        $labels = $labels->merge(defaultLabels($this->application->id, $this->application->uuid, $this->pull_request_id))->toArray();
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->container_name => [
                    'image' => $this->production_image_name,
                    'container_name' => $this->container_name,
                    'restart' => RESTART_MODE,
                    'environment' => $environment_variables,
                    'labels' => $labels,
                    'expose' => $ports,
                    'networks' => [
                        $this->destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            $this->generate_healthcheck_commands()
                        ],
                        'interval' => $this->application->health_check_interval . 's',
                        'timeout' => $this->application->health_check_timeout . 's',
                        'retries' => $this->application->health_check_retries,
                        'start_period' => $this->application->health_check_start_period . 's'
                    ],
                    'mem_limit' => $this->application->limits_memory,
                    'memswap_limit' => $this->application->limits_memory_swap,
                    'mem_swappiness' => $this->application->limits_memory_swappiness,
                    'mem_reservation' => $this->application->limits_memory_reservation,
                    'cpus' => $this->application->limits_cpus,
                    'cpuset' => $this->application->limits_cpuset,
                    'cpu_shares' => $this->application->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $this->destination->network => [
                    'external' => true,
                    'name' => $this->destination->network,
                    'attachable' => true
                ]
            ]
        ];
        if ($this->application->isHealthcheckDisabled()) {
            data_forget($docker_compose, 'services.' . $this->container_name . '.healthcheck');
        }
        if (count($this->application->ports_mappings_array) > 0 && $this->pull_request_id === 0) {
            $docker_compose['services'][$this->container_name]['ports'] = $this->application->ports_mappings_array;
        }
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$this->container_name]['volumes'] = $persistent_storages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        // if ($this->build_pack === 'dockerfile') {
        //     $docker_compose['services'][$this->container_name]['build'] = [
        //         'context' => $this->workdir,
        //         'dockerfile' => $this->workdir . $this->dockerfile_location,
        //     ];
        // }
        $this->docker_compose = Yaml::dump($docker_compose, 10);
        $this->docker_compose_base64 = base64_encode($this->docker_compose);
        $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml"), "hidden" => true]);
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->application->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            if ($this->pull_request_id !== 0) {
                $volume_name = $volume_name . '-pr-' . $this->pull_request_id;
            }
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
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
                $name = $name . '-pr-' . $this->pull_request_id;
            }

            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names;
    }

    private function generate_environment_variables($ports)
    {
        $environment_variables = collect();
        // ray('Generate Environment Variables')->green();
        if ($this->pull_request_id === 0) {
            // ray($this->application->runtime_environment_variables)->green();
            foreach ($this->application->runtime_environment_variables as $env) {
                $environment_variables->push("$env->key=$env->value");
            }
        } else {
            // ray($this->application->runtime_environment_variables_preview)->green();
            foreach ($this->application->runtime_environment_variables_preview as $env) {
                $environment_variables->push("$env->key=$env->value");
            }
        }
        // Add PORT if not exists, use the first port as default
        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('PORT'))->isEmpty()) {
            $environment_variables->push("PORT={$ports[0]}");
        }
        return $environment_variables->all();
    }

    private function generate_healthcheck_commands()
    {
        if ($this->application->dockerfile || $this->application->build_pack === 'dockerfile' || $this->application->build_pack === 'dockerimage') {
            // TODO: disabled HC because there are several ways to hc a simple docker image, hard to figure out a good way. Like some docker images (pocketbase) does not have curl.
            return 'exit 0';
        }
        if (!$this->application->health_check_port) {
            $health_check_port = $this->application->ports_exposes_array[0];
        } else {
            $health_check_port = $this->application->health_check_port;
        }
        if ($this->application->health_check_path) {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}{$this->application->health_check_path} > /dev/null"
            ];
        } else {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$health_check_port}/"
            ];
        }
        return implode(' ', $generated_healthchecks_commands);
    }

    private function build_image()
    {
        $this->execute_remote_command([
            "echo -n 'Building docker image for your application. To check the current progress, click on Show Debug Logs.'",
        ]);

        if ($this->application->settings->is_static) {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "docker build $this->addHosts --network host -f {$this->workdir}/{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->build_image_name {$this->workdir}"), "hidden" => true
            ]);

            $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from=$this->build_image_name /app/{$this->application->publish_directory} .
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");

            $nginx_config = base64_encode("server {
                listen       80;
                listen  [::]:80;
                server_name  localhost;

                location / {
                    root   /usr/share/nginx/html;
                    index  index.html;
                    try_files \$uri \$uri.html \$uri/index.html \$uri/ /index.html =404;
                }

                error_page   500 502 503 504  /50x.html;
                location = /50x.html {
                    root   /usr/share/nginx/html;
                }
            }");
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d > {$this->workdir}/Dockerfile-prod")
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d > {$this->workdir}/nginx.conf")
                ],
                [
                    executeInDocker($this->deployment_uuid, "docker build $this->addHosts --network host -f {$this->workdir}/Dockerfile-prod {$this->build_args} --progress plain -t $this->production_image_name {$this->workdir}"), "hidden" => true
                ]
            );
        } else {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "docker build $this->addHosts --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} --progress plain -t $this->production_image_name {$this->workdir}"), "hidden" => true
            ]);
        }
    }

    private function stop_running_container(bool $force = false)
    {
        if ($this->currently_running_container_name) {
            if ($this->newVersionIsHealthy || $force) {
                $this->execute_remote_command(
                    ["echo -n 'Removing old version of your application.'"],
                    [executeInDocker($this->deployment_uuid, "docker rm -f $this->currently_running_container_name >/dev/null 2>&1"), "hidden" => true],
                );
            } else {
                $this->execute_remote_command(
                    ["echo -n 'New version is not healthy, rolling back to the old version.'"],
                    [executeInDocker($this->deployment_uuid, "docker rm -f $this->container_name >/dev/null 2>&1"), "hidden" => true],
                );
            }
        }
    }

    private function start_by_compose_file()
    {
        $this->execute_remote_command(
            ["echo -n 'Starting application (could take a while).'"],
            [executeInDocker($this->deployment_uuid, "docker compose --project-directory {$this->workdir} up --build -d"), "hidden" => true],
        );
    }

    private function generate_build_env_variables()
    {
        $this->build_args = collect(["--build-arg SOURCE_COMMIT=\"{$this->commit}\""]);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->build_environment_variables as $env) {
                $this->build_args->push("--build-arg {$env->key}=\"{$env->value}\"");
            }
        } else {
            foreach ($this->application->build_environment_variables_preview as $env) {
                $this->build_args->push("--build-arg {$env->key}=\"{$env->value}\"");
            }
        }

        $this->build_args = $this->build_args->implode(' ');
    }

    private function add_build_env_variables_to_dockerfile()
    {
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$this->workdir}/{$this->dockerfile_location}"), "hidden" => true, "save" => 'dockerfile'
        ]);
        $dockerfile = collect(Str::of($this->saved_outputs->get('dockerfile'))->trim()->explode("\n"));

        foreach ($this->application->build_environment_variables as $env) {
            $dockerfile->splice(1, 0, "ARG {$env->key}={$env->value}");
        }
        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d > {$this->workdir}/{$this->dockerfile_location}"),
            "hidden" => true
        ]);
    }

    private function next(string $status)
    {
        // If the deployment is cancelled by the user, don't update the status
        if ($this->application_deployment_queue->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->update([
                'status' => $status,
            ]);
        }
        queue_next_deployment($this->application);
        if ($status === ApplicationDeploymentStatus::FINISHED->value) {
            $this->application->environment->project->team->notify(new DeploymentSuccess($this->application, $this->deployment_uuid, $this->preview));
        }
        if ($status === ApplicationDeploymentStatus::FAILED->value) {
            $this->application->environment->project->team->notify(new DeploymentFailed($this->application, $this->deployment_uuid, $this->preview));
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->execute_remote_command(
            ["echo 'Oops something is not okay, are you okay? 😢'"],
            ["echo '{$exception->getMessage()}'"]
        );
        $this->next(ApplicationDeploymentStatus::FAILED->value);
    }
}
