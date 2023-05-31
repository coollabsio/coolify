<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private Application $application;
    private ApplicationDeploymentQueue $application_deployment_queue;
    private $destination;
    private $source;
    private Activity $activity;

    private string|null $git_commit = null;
    private string $workdir;
    private string $docker_compose;
    private $build_args;
    private $env_args;
    private string $build_image_name;
    private string $production_image_name;
    private string $container_name;
    private ApplicationPreview|null $preview;

    public static int $batch_counter = 0;
    public $timeout = 10200;

    public function __construct(
        public int $application_deployment_queue_id,
        public string $deployment_uuid,
        public string $application_id,
        public bool $force_rebuild = false,
        public string $rollback_commit = 'HEAD',
        public int $pull_request_id = 0,
    ) {
        $this->application_deployment_queue = ApplicationDeploymentQueue::find($this->application_deployment_queue_id);
        $this->application_deployment_queue->update([
            'status' => ProcessStatus::IN_PROGRESS->value,
        ]);
        if ($this->rollback_commit) {
            $this->git_commit = $this->rollback_commit;
        }

        $this->application = Application::find($this->application_id);

        if ($this->pull_request_id) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
        }

        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();

        $server = $this->destination->server;

        $private_key_location = save_private_key_for_server($server);

        $remoteProcessArgs = new CoolifyTaskArgs(
            server_ip: $server->ip,
            private_key_location: $private_key_location,
            command: 'overwritten-later',
            port: $server->port,
            user: $server->user,
            type: ActivityTypes::DEPLOYMENT->value,
            type_uuid: $this->deployment_uuid,
        );

        $this->activity = activity()
            ->performedOn($this->application)
            ->withProperties($remoteProcessArgs->toArray())
            ->event(ActivityTypes::DEPLOYMENT->value)
            ->log("[]");
    }
    public function handle(): void
    {
        try {
            if ($this->application->deploymentType() === 'source') {
                $this->source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();
            }

            $this->workdir = "/artifacts/{$this->deployment_uuid}";
            if ($this->pull_request_id) {
                ray('Deploying pull/' . $this->pull_request_id . '/head for application: ' . $this->application->name);
                $this->deploy_pull_request();
            } else {
                $this->deploy();
            }
        } catch (\Exception $e) {
            $this->execute_now([
                "echo '\nOops something is not okay, are you okay? 😢'",
                "echo '\n\n{$e->getMessage()}'",
            ]);
            $this->fail();
        } finally {
            if (isset($this->docker_compose)) {
                Storage::disk('deployments')->put(Str::kebab($this->application->name) . '/docker-compose.yml', $this->docker_compose);
            }
            $this->execute_now(["docker rm -f {$this->deployment_uuid} >/dev/null 2>&1"], hideFromOutput: true);
        }
    }

    private function start_builder_image()
    {
        $this->execute_now([
            "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder)... '",
        ]);
        $this->execute_now([
            "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder",
        ], isDebuggable: true);
        $this->execute_now([
            "echo 'Done.'"
        ]);
        $this->execute_now([
            $this->execute_in_builder("mkdir -p {$this->workdir}"),
        ]);
    }

    private function clone_repository()
    {
        $this->execute_now([
            "echo -n 'Importing {$this->application->git_repository}:{$this->application->git_branch} to {$this->workdir}... '"
        ]);

        $this->execute_now([
            ...$this->importing_git_repository(),
        ], 'importing_git_repository');

        $this->execute_now([
            "echo 'Done.'"
        ]);
        // Get git commit
        $this->execute_now([$this->execute_in_builder("cd {$this->workdir} && git rev-parse HEAD")], 'commit_sha', hideFromOutput: true);
        $this->git_commit = $this->activity->properties->get('commit_sha');
    }

    private function cleanup_git()
    {
        $this->execute_now([
            $this->execute_in_builder("rm -fr {$this->workdir}/.git")
        ], hideFromOutput: true);
    }
    private function generate_buildpack()
    {
        $this->execute_now([
            "echo -n 'Generating nixpacks configuration... '",
        ]);
        $this->execute_now([
            $this->nixpacks_build_cmd(),
            $this->execute_in_builder("cp {$this->workdir}/.nixpacks/Dockerfile {$this->workdir}/Dockerfile"),
            $this->execute_in_builder("rm -f {$this->workdir}/.nixpacks/Dockerfile"),
        ], isDebuggable: true);
    }
    private function build_image()
    {
        $this->execute_now([
            "echo -n 'Building image... '",
        ]);

        if ($this->application->settings->is_static) {
            $this->execute_now([
                $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t { $this->build_image_name {$this->workdir}"),
            ], isDebuggable: true);

            $dockerfile = "FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from=$this->build_image_name /app/{$this->application->publish_directory} .";
            $docker_file = base64_encode($dockerfile);

            $this->execute_now([
                $this->execute_in_builder("echo '{$docker_file}' | base64 -d > {$this->workdir}/Dockerfile-prod"),
                $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile-prod {$this->build_args} --progress plain -t $this->production_image_name {$this->workdir}"),
            ], hideFromOutput: true);
        } else {
            $this->execute_now([
                $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t $this->production_image_name {$this->workdir}"),
            ], isDebuggable: true);
        }
        $this->execute_now([
            "echo 'Done.'",
        ]);
    }
    private function deploy_pull_request()
    {
        $this->build_image_name = "{$this->application->uuid}:pr-{$this->pull_request_id}-build";
        $this->production_image_name = "{$this->application->uuid}:pr-{$this->pull_request_id}";
        $this->container_name = generate_container_name($this->application->uuid, $this->pull_request_id);
        // Deploy pull request
        $this->execute_now([
            "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch} PR#{$this->pull_request_id}...'",
        ]);
        $this->start_builder_image();
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_buildpack();
        $this->generate_compose_file();
        // Needs separate preview variables
        // $this->generate_build_env_variables();
        // $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->stop_running_container();
        $this->start_by_compose_file();
        $this->next(ProcessStatus::FINISHED->value);
    }
    private function deploy()
    {
        $this->container_name = generate_container_name($this->application->uuid);
        // Deploy normal commit
        $this->execute_now([
            "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}...'",
        ]);
        $this->start_builder_image();
        ray('Rollback Commit: ' . $this->rollback_commit);
        if ($this->rollback_commit === 'HEAD') {
            $this->clone_repository();
        }
        $this->build_image_name = "{$this->application->uuid}:{$this->git_commit}-build";
        $this->production_image_name = "{$this->application->uuid}:{$this->git_commit}";
        ray('Build Image Name: ' . $this->build_image_name . ' & Production Image Name:' . $this->production_image_name);
        if (!$this->force_rebuild) {
            $this->execute_now([
                "docker images -q {$this->application->uuid}:{$this->git_commit} 2>/dev/null",
            ], 'local_image_found', hideFromOutput: true, ignoreErrors: true);
            $image_found = Str::of($this->activity->properties->get('local_image_found'))->trim()->isNotEmpty();
            if ($image_found) {
                $this->execute_now([
                    "echo 'Docker Image found locally with the same Git Commit SHA. Build skipped...'"
                ]);
                $this->generate_compose_file();
                $this->stop_running_container();
                $this->start_by_compose_file();
                $this->next(ProcessStatus::FINISHED->value);
                return;
            }
        }
        $this->cleanup_git();
        $this->generate_buildpack();
        $this->generate_compose_file();
        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();
        $this->stop_running_container();
        $this->start_by_compose_file();
        $this->next(ProcessStatus::FINISHED->value);
    }

    public function failed(): void
    {
        $this->next(ProcessStatus::ERROR->value);
    }

    private function next(string $status)
    {
        ray($this->application_deployment_queue->status, Str::of($this->application_deployment_queue->status)->startsWith('cancelled'));
        if (!Str::of($this->application_deployment_queue->status)->startsWith('cancelled')) {
            $this->application_deployment_queue->update([
                'status' => $status,
            ]);
        }
        dispatch(new ContainerStatusJob(
            application: $this->application,
            container_name: $this->container_name,
            pull_request_id: $this->pull_request_id
        ));

        queue_next_deployment($this->application);
    }
    private function execute_in_builder(string $command)
    {
        return "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }
    private function generate_environment_variables($ports)
    {
        $environment_variables = collect();

        foreach ($this->application->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
        // Add PORT if not exists, use the first port as default
        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('PORT'))->isEmpty()) {
            $environment_variables->push("PORT={$ports[0]}");
        }
        return $environment_variables->all();
    }
    private function generate_env_variables()
    {
        $this->env_args = collect([]);
        foreach ($this->application->nixpacks_environment_variables as $env) {
            $this->env_args->push("--env {$env->key}={$env->value}");
        }
        $this->env_args = $this->env_args->implode(' ');
    }
    private function generate_build_env_variables()
    {
        $this->build_args = collect(["--build-arg SOURCE_COMMIT={$this->git_commit}"]);
        foreach ($this->application->build_environment_variables as $env) {
            $this->build_args->push("--build-arg {$env->key}={$env->value}");
        }
        $this->build_args = $this->build_args->implode(' ');
    }
    private function add_build_env_variables_to_dockerfile()
    {
        $this->execute_now([
            $this->execute_in_builder("cat {$this->workdir}/Dockerfile")
        ], propertyName: 'dockerfile', hideFromOutput: true);
        $dockerfile = collect(Str::of($this->activity->properties->get('dockerfile'))->trim()->explode("\n"));

        foreach ($this->application->build_environment_variables as $env) {
            $dockerfile->splice(1, 0, "ARG {$env->key}={$env->value}");
        }
        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->execute_now([
            $this->execute_in_builder("echo '{$dockerfile_base64}' | base64 -d > {$this->workdir}/Dockerfile")
        ], hideFromOutput: true);
    }
    private function generate_docker_compose()
    {
        $ports = $this->application->settings->is_static ? [80] : $this->application->ports_exposes_array;
        if ($this->pull_request_id) {
            $persistent_storages = [];
            $volume_names = [];
            $environment_variables = [];
        } else {
            $persistent_storages = $this->generate_local_persistent_volumes();
            $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
            $environment_variables = $this->generate_environment_variables($ports);
        }
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->container_name => [
                    'image' => $this->production_image_name,
                    'container_name' => $this->container_name,
                    'restart' => 'always',
                    'environment' => $environment_variables,
                    'labels' => $this->set_labels_for_applications(),
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
                    'external' => false,
                    'name' => $this->destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        if (count($this->application->ports_mappings_array) > 0 && !$this->pull_request_id) {
            $docker_compose['services'][$this->container_name]['ports'] = $this->application->ports_mappings_array;
        }
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$this->container_name]['volumes'] = $persistent_storages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        return Yaml::dump($docker_compose, 10);
    }
    private function generate_local_persistent_volumes()
    {
        foreach ($this->application->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
        }
        return $local_persistent_volumes ?? [];
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $local_persistent_volumes_names[$persistentStorage->name] = [
                'name' => $persistentStorage->name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names ?? [];
    }
    private function generate_healthcheck_commands()
    {
        if (!$this->application->health_check_port) {
            $this->application->health_check_port = $this->application->ports_exposes_array[0];
        }
        if ($this->application->health_check_path) {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$this->application->health_check_port}{$this->application->health_check_path} > /dev/null"
            ];
        } else {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$this->application->health_check_port}/"
            ];
        }
        return implode(' ', $generated_healthchecks_commands);
    }

    private function set_labels_for_applications()
    {
        $labels = [];
        $labels[] = 'coolify.managed=true';
        $labels[] = 'coolify.version=' . config('version');
        $labels[] = 'coolify.applicationId=' . $this->application->id;
        $labels[] = 'coolify.type=application';
        $labels[] = 'coolify.name=' . $this->application->name;
        if ($this->pull_request_id) {
            $labels[] = 'coolify.pullRequestId=' . $this->pull_request_id;
        }
        if ($this->application->fqdn) {
            if ($this->pull_request_id) {
                $preview_fqdn = data_get($this->preview, 'fqdn');
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
                $domains = Str::of($preview_fqdn)->explode(',');
            } else {
                $domains = Str::of($this->application->fqdn)->explode(',');
            }
            $labels[] = 'traefik.enable=true';
            foreach ($domains as $domain) {
                $url = Url::fromString($domain);
                $host = $url->getHost();
                $path = $url->getPath();
                $schema = $url->getScheme();
                $slug = Str::slug($host . $path);

                $http_label = "{$this->application->uuid}-{$slug}-http";
                $https_label = "{$this->application->uuid}-{$slug}-https";

                if ($schema === 'https') {
                    // Set labels for https
                    $labels[] = "traefik.http.routers.{$https_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                    $labels[] = "traefik.http.routers.{$https_label}.entryPoints=https";
                    $labels[] = "traefik.http.routers.{$https_label}.middlewares=gzip";
                    if ($path !== '/') {
                        $labels[] = "traefik.http.routers.{$https_label}.middlewares={$https_label}-stripprefix";
                        $labels[] = "traefik.http.middlewares.{$https_label}-stripprefix.stripprefix.prefixes={$path}";
                    }

                    $labels[] = "traefik.http.routers.{$https_label}.tls=true";
                    $labels[] = "traefik.http.routers.{$https_label}.tls.certresolver=letsencrypt";

                    // Set labels for http (redirect to https)
                    $labels[] = "traefik.http.routers.{$http_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                    $labels[] = "traefik.http.routers.{$http_label}.entryPoints=http";
                    if ($this->application->settings->is_force_https_enabled) {
                        $labels[] = "traefik.http.routers.{$http_label}.middlewares=redirect-to-https";
                    }
                } else {
                    // Set labels for http
                    $labels[] = "traefik.http.routers.{$http_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                    $labels[] = "traefik.http.routers.{$http_label}.entryPoints=http";
                    $labels[] = "traefik.http.routers.{$http_label}.middlewares=gzip";
                    if ($path !== '/') {
                        $labels[] = "traefik.http.routers.{$http_label}.middlewares={$http_label}-stripprefix";
                        $labels[] = "traefik.http.middlewares.{$http_label}-stripprefix.stripprefix.prefixes={$path}";
                    }
                }
            }
        }
        return $labels;
    }

    private function execute_now(
        array|Collection $command,
        string $propertyName = null,
        bool $isFinished = false,
        bool $hideFromOutput = false,
        bool $isDebuggable = false,
        bool $ignoreErrors = false
    ) {
        static::$batch_counter++;

        if ($command instanceof Collection) {
            $commandText = $command->implode("\n");
        } else {
            $commandText = collect($command)->implode("\n");
        }
        ray('Executing command', $commandText);
        $this->activity->properties = $this->activity->properties->merge([
            'command' => $commandText,
        ]);
        $this->activity->save();
        if ($isDebuggable && !$this->application->settings->is_debug_enabled) {
            $hideFromOutput = true;
        }
        $remote_process = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'hideFromOutput' => $hideFromOutput,
            'isFinished' => $isFinished,
            'ignoreErrors' => $ignoreErrors,
        ]);
        $result = $remote_process();
        if ($propertyName) {
            $this->activity->properties = $this->activity->properties->merge([
                $propertyName => trim($result->output()),
            ]);
            $this->activity->save();
        }

        if ($result->exitCode() != 0 && $result->errorOutput() && !$ignoreErrors) {
            throw new \RuntimeException($result->errorOutput());
        }
    }
    private function set_git_import_settings($git_clone_command)
    {
        if ($this->application->git_commit_sha !== 'HEAD') {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git -c advice.detachedHead=false checkout {$this->application->git_commit_sha} >/dev/null 2>&1";
        }
        if ($this->application->settings->is_git_submodules_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git submodule update --init --recursive";
        }
        if ($this->application->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git lfs pull";
        }
        return $git_clone_command;
    }
    private function importing_git_repository()
    {
        $git_clone_command = "git clone -q -b {$this->application->git_branch}";
        if ($this->pull_request_id) {
            $pr_branch_name = "pr-{$this->pull_request_id}-coolify";
        }

        if ($this->application->deploymentType() === 'source') {
            $source_html_url = data_get($this->application, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                if ($this->source->is_public) {
                    $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$this->application->git_repository} {$this->workdir}";
                    $git_clone_command = $this->set_git_import_settings($git_clone_command);

                    $commands = [$this->execute_in_builder($git_clone_command)];

                    if ($this->pull_request_id) {
                        $commands[] = $this->execute_in_builder("cd {$this->workdir} && git fetch origin pull/{$this->pull_request_id}/head:$pr_branch_name >/dev/null 2>&1 && git checkout $pr_branch_name >/dev/null 2>&1");
                    }
                    return $commands;
                } else {
                    $github_access_token = generate_github_installation_token($this->source);
                    $commands = [
                        $this->execute_in_builder("git clone -q -b {$this->application->git_branch} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git {$this->workdir}")
                    ];
                    if ($this->pull_request_id) {
                        $commands[] = $this->execute_in_builder("cd {$this->workdir} && git fetch origin pull/{$this->pull_request_id}/head:$pr_branch_name && git checkout $pr_branch_name");
                    }
                    return $commands;
                }
            }
        }
        if ($this->application->deploymentType() === 'deploy_key') {
            $private_key = base64_encode($this->application->private_key->private_key);
            $git_clone_command = "GIT_SSH_COMMAND=\"ssh -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$git_clone_command} {$this->application->git_full_url} {$this->workdir}";
            $git_clone_command = $this->set_git_import_settings($git_clone_command);
            return [
                $this->execute_in_builder("mkdir -p /root/.ssh"),
                $this->execute_in_builder("echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa"),
                $this->execute_in_builder("chmod 600 /root/.ssh/id_rsa"),
                $this->execute_in_builder($git_clone_command)
            ];
        }
    }
    private function nixpacks_build_cmd()
    {
        $this->generate_env_variables();
        $nixpacks_command = "nixpacks build -o {$this->workdir} {$this->env_args} --no-error-without-start";
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
        ray('Build command: ' . $nixpacks_command);
        return $this->execute_in_builder($nixpacks_command);
    }
    private function stop_running_container()
    {
        $this->execute_now([
            "echo -n 'Removing old instance... '",
            $this->execute_in_builder("docker rm -f $this->container_name >/dev/null 2>&1"),
            "echo 'Done.'",
        ]);
    }
    private function start_by_compose_file()
    {
        $this->execute_now([
            "echo -n 'Starting your application... '",
        ]);
        $this->execute_now([
            $this->execute_in_builder("docker compose --project-directory {$this->workdir} up -d >/dev/null"),
        ], isDebuggable: true);
        $this->execute_now([
            "echo 'Done. 🎉'",
        ], isFinished: true);
    }
    private function generate_compose_file()
    {
        $this->docker_compose = $this->generate_docker_compose();
        $docker_compose_base64 = base64_encode($this->docker_compose);
        $this->execute_now([
            $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml")
        ], hideFromOutput: true);
    }
}
