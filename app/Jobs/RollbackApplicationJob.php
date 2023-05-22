<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
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

class RollbackApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $application;
    protected $destination;
    protected $source;
    protected Activity $activity;
    protected string $git_commit;
    protected string $workdir;
    protected string $docker_compose;
    protected $build_args;
    protected $env_args;
    public static int $batch_counter = 0;
    public $timeout = 3600;
    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deployment_uuid,
        public string $application_uuid,
        public string $commit,
    ) {

        $this->application = Application::query()
            ->where('uuid', $this->application_uuid)
            ->firstOrFail();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
        $server = $this->destination->server;

        $private_key_location = savePrivateKeyForServer($server);

        $this->git_commit = $commit;

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
    protected function stopRunningContainer()
    {
        $this->executeNow([
            "echo -n 'Removing old instance... '",
            $this->execute_in_builder("docker rm -f {$this->application->uuid} >/dev/null 2>&1"),
            "echo 'Done.'",
            "echo -n 'Starting your application... '",
        ]);
    }
    protected function startByComposeFile()
    {
        $this->executeNow([
            $this->execute_in_builder("docker compose --project-directory {$this->workdir} up -d >/dev/null"),
        ], isDebuggable: true);
        $this->executeNow([
            "echo 'Done. 🎉'",
        ], isFinished: true);
    }
    protected function generateComposeFile()
    {
        $this->docker_compose = $this->generate_docker_compose();
        $docker_compose_base64 = base64_encode($this->docker_compose);
        $this->executeNow([
            $this->execute_in_builder("mkdir -p {$this->workdir}"),
            $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml")
        ], hideFromOutput: true);
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->workdir = "/artifacts/{$this->deployment_uuid}";
            $this->executeNow([
                "echo 'Starting rollback of {$this->application->git_repository}:{$this->application->git_branch} to {$this->git_commit}...'",
                "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder)... '",
            ]);

            $this->executeNow([
                "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder",
            ], isDebuggable: true);

            $this->executeNow([
                "echo 'Done.'",
                "echo -n 'Checking if the image is available... '",
            ]);

            $this->executeNow([
                "docker images -q {$this->application->uuid}:{$this->git_commit} 2>/dev/null",
            ], 'local_image_found', hideFromOutput: true, ignoreErrors: true);
            $image_found = Str::of($this->activity->properties->get('local_image_found'))->trim()->isNotEmpty();
            if ($image_found) {
                $this->executeNow([
                    "echo 'Yes, it is available.'",
                ]);
                // Generate docker-compose.yml
                $this->generateComposeFile();

                // Stop running container
                $this->stopRunningContainer();

                // Start application
                $this->startByComposeFile();

                $this->application->git_commit_sha = $this->git_commit;
                $this->application->settings->is_auto_deploy = false;
                $this->application->settings->save();
                $this->application->save();

                $this->executeNow([
                    "echo 'Auto deployments are disabled for this application to prevent overwritten automatically.'",
                    "echo 'Commit SHA set to {$this->git_commit} in the Source menu.'",
                ]);
                return;
            }
            throw new \Exception('Docker Image not found locally with the same Git Commit SHA.');
        } catch (\Exception $e) {
            $this->executeNow([
                "echo '\nOops something is not okay, are you okay? 😢'",
                "echo '\n\n{$e->getMessage()}'",
            ]);
            $this->fail($e->getMessage());
        } finally {
            // Saving docker-compose.yml
            if (isset($this->docker_compose)) {
                Storage::disk('deployments')->put(Str::kebab($this->application->name) . '/docker-compose.yml', $this->docker_compose);
            }
            $this->executeNow(["docker rm -f {$this->deployment_uuid} >/dev/null 2>&1"], hideFromOutput: true);
            dispatch(new ContainerStatusJob($this->application_uuid));
        }
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
        $this->executeNow([
            $this->execute_in_builder("cat {$this->workdir}/Dockerfile")
        ], propertyName: 'dockerfile', hideFromOutput: true);
        $dockerfile = collect(Str::of($this->activity->properties->get('dockerfile'))->trim()->explode("\n"));

        foreach ($this->application->build_environment_variables as $env) {
            $dockerfile->splice(1, 0, "ARG {$env->key}={$env->value}");
        }
        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->executeNow([
            $this->execute_in_builder("echo '{$dockerfile_base64}' | base64 -d > {$this->workdir}/Dockerfile")
        ], hideFromOutput: true);
    }
    private function generate_docker_compose()
    {
        $ports = $this->application->settings->is_static ? [80] : $this->application->ports_exposes_array;
        $persistentStorages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables($ports);
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->application->uuid => [
                    'image' => "{$this->application->uuid}:$this->git_commit",
                    'container_name' => $this->application->uuid,
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
                    'oom_kill_disable' => $this->application->limits_memory_oom_kill,
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
        if (count($this->application->ports_mappings_array) > 0) {
            $docker_compose['services'][$this->application->uuid]['ports'] = $this->application->ports_mappings_array;
        }
        if (count($persistentStorages) > 0) {
            $docker_compose['services'][$this->application->uuid]['volumes'] = $persistentStorages;
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
        if ($this->application->fqdn) {
            $domains = Str::of($this->application->fqdn)->explode(',');
            $labels[] = 'traefik.enable=true';
            foreach ($domains as $domain) {
                $url = Url::fromString($domain);
                $host = $url->getHost();
                $path = $url->getPath();
                $slug = Str::slug($url);
                $label_id = "{$this->application->uuid}-{$slug}";
                if ($path === '/') {
                    $labels[] = "traefik.http.routers.{$label_id}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                } else {
                    $labels[] = "traefik.http.routers.{$label_id}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                    $labels[] =  "traefik.http.routers.{$label_id}.middlewares={$label_id}-stripprefix";
                    $labels[] =  "traefik.http.middlewares.{$label_id}-stripprefix.stripprefix.prefixes={$path}";
                }
            }
        }
        return $labels;
    }

    private function executeNow(
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

        $this->activity->properties = $this->activity->properties->merge([
            'command' => $commandText,
        ]);
        $this->activity->save();
        if ($isDebuggable && !$this->application->settings->is_debug) {
            $hideFromOutput = true;
        }
        $remoteProcess = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'hideFromOutput' => $hideFromOutput,
            'isFinished' => $isFinished,
            'ignoreErrors' => $ignoreErrors,
        ]);
        $result = $remoteProcess();
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
}
