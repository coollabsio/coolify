<?php

namespace App\Jobs;

use App\Actions\RemoteProcess\RunRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
use App\Models\InstanceSettings;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;

class DeployApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $application;
    protected $destination;
    protected $source;
    protected Activity $activity;
    protected string $git_commit;
    protected string $workdir;
    protected string $docker_compose;
    public static int $batch_counter = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deployment_uuid,
        public string $application_uuid,
        public bool $force_rebuild = false,
    ) {
        $this->application = Application::query()
            ->where('uuid', $this->application_uuid)
            ->firstOrFail();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();

        $server = $this->destination->server;

        $private_key_location = savePrivateKeyForServer($server);

        $remoteProcessArgs = new RemoteProcessArgs(
            server_ip: $server->ip,
            private_key_location: $private_key_location,
            deployment_uuid: $this->deployment_uuid,
            command: 'overwritten-later',
            port: $server->port,
            user: $server->user,
            type: ActivityTypes::DEPLOYMENT->value,
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
            $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml")
        ], hideFromOutput: true);
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $coolify_instance_settings = InstanceSettings::find(0);
            $this->source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();

            // Get Wildcard Domain
            $project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
            $global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
            $wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

            // Set wildcard domain
            if (!$this->application->fqdn && $wildcard_domain) {
                $this->application->fqdn = 'http://' . $this->application->uuid . '.' . $wildcard_domain;
                $this->application->save();
            }
            $this->workdir = "/artifacts/{$this->deployment_uuid}";

            // Pull builder image
            $this->executeNow([
                "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}...'",
                "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder)... '",
                "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder >/dev/null 2>&1",
                "echo 'Done.'",
            ]);

            // Import git repository
            $this->executeNow([
                "echo -n 'Importing {$this->application->git_repository}:{$this->application->git_branch} to {$this->workdir}... '"
            ]);

            $this->executeNow([
                ...$this->gitImport(),
            ], 'importing_git_repository');

            $this->executeNow([
                "echo 'Done.'"
            ]);

            // Get git commit
            $this->executeNow([$this->execute_in_builder("cd {$this->workdir} && git rev-parse HEAD")], 'commit_sha', hideFromOutput: true);
            $this->git_commit = $this->activity->properties->get('commit_sha');

            if (!$this->force_rebuild) {
                $this->executeNow([
                    "docker images -q {$this->application->uuid}:{$this->git_commit} 2>/dev/null",
                ], 'local_image_found', hideFromOutput: true, ignoreErrors: true);
                $image_found = Str::of($this->activity->properties->get('local_image_found'))->trim()->isNotEmpty();
                if ($image_found) {
                    $this->executeNow([
                        "echo 'Docker Image found locally with the same Git Commit SHA. Build skipped...'"
                    ]);
                    // Generate docker-compose.yml
                    $this->generateComposeFile();

                    // Stop running container
                    $this->stopRunningContainer();

                    // Start application
                    $this->startByComposeFile();
                    return;
                }
            }
            $this->executeNow([
                $this->execute_in_builder("rm -fr {$this->workdir}/.git")
            ], hideFromOutput: true);

            // Generate docker-compose.yml
            $this->generateComposeFile();

            $this->executeNow([
                "echo -n 'Generating nixpacks configuration... '",
            ]);
            $this->executeNow([
                $this->nixpacks_build_cmd(),
                $this->execute_in_builder("cp {$this->workdir}/.nixpacks/Dockerfile {$this->workdir}/Dockerfile"),
                $this->execute_in_builder("rm -f {$this->workdir}/.nixpacks/Dockerfile"),
            ], isDebuggable: true);

            $this->executeNow([
                "echo 'Done.'",
                "echo -n 'Building image... '",
            ]);

            if ($this->application->settings->is_static) {
                $this->executeNow([
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile --build-arg SOURCE_COMMIT={$this->git_commit} --progress plain -t {$this->application->uuid}:{$this->git_commit}-build {$this->workdir}"),
                ], isDebuggable: true);

                $dockerfile = "FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from={$this->application->uuid}:{$this->git_commit}-build /app/{$this->application->publish_directory} .";
                $docker_file = base64_encode($dockerfile);

                $this->executeNow([
                    $this->execute_in_builder("echo '{$docker_file}' | base64 -d > {$this->workdir}/Dockerfile-prod"),
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile-prod --build-arg SOURCE_COMMIT={$this->git_commit} --progress plain -t {$this->application->uuid}:{$this->git_commit} {$this->workdir}"),
                ], hideFromOutput: true);
            } else {
                $this->executeNow([
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile --build-arg SOURCE_COMMIT={$this->git_commit} --progress plain -t {$this->application->uuid}:{$this->git_commit} {$this->workdir}"),
                ], isDebuggable: true);
            }

            $this->executeNow([
                "echo 'Done.'",
            ]);
            // Stop running container
            $this->stopRunningContainer();

            // Start application
            $this->startByComposeFile();

            // Saving docker-compose.yml
            Storage::disk('deployments')->put(Str::kebab($this->application->name) . '/docker-compose.yml', $this->docker_compose);
        } catch (\Exception $e) {
            $this->executeNow([
                "echo 'Oops something is not okay, are you okay? 😢'",
                "echo '\n\n{$e->getMessage()}'",
            ]);
            throw new \Exception('Deployment finished');
        } finally {
            $this->executeNow(["docker rm -f {$this->deployment_uuid} >/dev/null 2>&1"], hideFromOutput: true);
            dispatch(new ContainerStatusJob($this->application_uuid));
        }
    }

    private function execute_in_builder(string $command)
    {
        return "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }

    private function generate_docker_compose()
    {
        $persistentStorages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $ports = $this->application->settings->is_static ? [80] : $this->application->ports_exposes_array;
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->application->uuid => [
                    'image' => "{$this->application->uuid}:$this->git_commit",
                    'container_name' => $this->application->uuid,
                    'restart' => 'always',
                    'environment' => [
                        'PORT' => $ports[0]
                    ],
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

    private function generate_jwt_token_for_github()
    {
        $signingKey = InMemory::plainText($this->source->privateKey->private_key);
        $algorithm = new Sha256();
        $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
        $now = new DateTimeImmutable();
        $now = $now->setTime($now->format('H'), $now->format('i'));
        $issuedToken = $tokenBuilder
            ->issuedBy($this->source->app_id)
            ->issuedAt($now)
            ->expiresAt($now->modify('+10 minutes'))
            ->getToken($algorithm, $signingKey)
            ->toString();
        $token = Http::withHeaders([
            'Authorization' => "Bearer $issuedToken",
            'Accept' => 'application/vnd.github.machine-man-preview+json'
        ])->post("{$this->source->api_url}/app/installations/{$this->source->installation_id}/access_tokens");
        if ($token->failed()) {
            throw new \Exception("Failed to get access token for $this->application->name from " . $this->source->name . " with error: " . $token->json()['message']);
        }
        return $token->json()['token'];
    }

    private function set_labels_for_applications()
    {
        $labels = [];
        $labels[] = 'coolify.managed=true';
        $labels[] = 'coolify.version=' . config('coolify.version');
        $labels[] = 'coolify.applicationId=' . $this->application->id;
        $labels[] = 'coolify.type=application';
        $labels[] = 'coolify.name=' . $this->application->name;
        if ($this->application->fqdn) {
            $labels[] = "traefik.http.routers.container.rule=Host(`{$this->application->fqdn}`)";
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
    private function setGitImportSettings($git_clone_command)
    {
        if ($this->application->git_commit_sha) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git checkout {$this->application->git_commit_sha}";
        }
        if ($this->application->settings->is_git_submodules_allowed) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git submodule update --init --recursive";
        }
        if ($this->application->settings->is_git_lfs_allowed) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git lfs pull";
        }
        return $git_clone_command;
    }
    private function gitImport()
    {
        $source_html_url = data_get($this->application, 'source.html_url');
        $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
        $source_html_url_host = $url['host'];
        $source_html_url_scheme = $url['scheme'];

        $git_clone_command = "git clone -q -b {$this->application->git_branch}";

        if ($this->application->source->getMorphClass() == 'App\Models\GithubApp') {
            if ($this->source->is_public) {
                $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$this->application->git_repository} {$this->workdir}";
                $git_clone_command = $this->setGitImportSettings($git_clone_command);
                return [
                    $this->execute_in_builder($git_clone_command)
                ];
            } else {
                if (!$this->application->source->app_id) {
                    $private_key = base64_encode($this->application->source->privateKey->private_key);

                    $git_clone_command = "GIT_SSH_COMMAND=\"ssh -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$git_clone_command} git@$source_html_url_host:{$this->application->git_repository}.git {$this->workdir}";
                    $git_clone_command = $this->setGitImportSettings($git_clone_command);

                    return [
                        $this->execute_in_builder("mkdir -p /root/.ssh"),
                        $this->execute_in_builder("echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa"),
                        $this->execute_in_builder("chmod 600 /root/.ssh/id_rsa"),
                        $this->execute_in_builder($git_clone_command)
                    ];
                } else {
                    $github_access_token = $this->generate_jwt_token_for_github();
                    return [
                        $this->execute_in_builder("git clone -q -b {$this->application->git_branch} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git {$this->workdir}")
                    ];
                }
            }
        }
    }
    private function nixpacks_build_cmd()
    {
        $nixpacks_command = "nixpacks build -o {$this->workdir} --no-error-without-start";
        if ($this->application->install_command) {
            $nixpacks_command .= " --install-cmd '{$this->application->install_command}'";
        }
        if ($this->application->build_command) {
            $nixpacks_command .= " --build-cmd '{$this->application->build_command}'";
        }
        if ($this->application->start_command) {
            $nixpacks_command .= " --start-cmd '{$this->application->start_command}'";
        }
        $nixpacks_command .= " {$this->workdir}";
        return $this->execute_in_builder($nixpacks_command);
    }
}
