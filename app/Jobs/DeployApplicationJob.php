<?php

namespace App\Jobs;

use App\Actions\RemoteProcess\RunRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
use App\Models\CoolifyInstanceSettings;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

class DeployApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $application;
    protected $destination;
    protected $source;
    protected Activity $activity;
    protected string $git_commit;
    protected string $workdir;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deployment_uuid,
        public string $application_uuid,
    ) {
        $this->application = Application::query()
            ->where('uuid', $this->application_uuid)
            ->firstOrFail();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();

        $server = $this->destination->server;

        $private_key_location = savePrivateKey($server);

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

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $coolify_instance_settings = CoolifyInstanceSettings::find(1);
        $this->source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();

        // Get Wildcard Domain
        $project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
        $global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
        $wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

        // Set wildcard domain
        if (!$this->application->settings->is_bot && !$this->application->fqdn && $wildcard_domain) {
            $this->application->fqdn = $this->application->uuid . '.' . $wildcard_domain;
            $this->application->save();
        }
        $this->workdir = "/artifacts/{$this->deployment_uuid}";

        // Pull builder image
        $this->executeNow([
            "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}...'",
            "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder)... '",
            "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder >/dev/null 2>&1",
            "echo 'Done.'",
        ], 'docker_pull_builder_image');

        // Import git repository
        $this->executeNow([
            "echo -n 'Importing {$this->application->git_repository}:{$this->application->git_branch} to {$this->workdir}... '",
            $this->gitImport(),
            "echo 'Done.'"
        ], 'importing_git_repository');

        // Get git commit
        $this->executeNow([$this->execute_in_builder("cd {$this->workdir} && git rev-parse HEAD")], 'commit_sha', hideFromOutput: true);
        $this->git_commit = $this->activity->properties->get('commit_sha');

        $this->executeNow([
            $this->execute_in_builder("rm -fr {$this->workdir}/.git")
        ], hideFromOutput: true);

        $docker_compose_base64 = base64_encode($this->generate_docker_compose());
        $this->executeNow([
            $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml")
        ], hideFromOutput: true);

        $this->executeNow([
            "echo -n 'Generating nixpacks configuration... '",
            $this->nixpacks_build_cmd(),
            $this->execute_in_builder("cp {$this->workdir}/.nixpacks/Dockerfile {$this->workdir}/Dockerfile"),
            $this->execute_in_builder("rm -f {$this->workdir}/.nixpacks/Dockerfile"),
            "echo 'Done.'",
        ]);
        $this->executeNow([
            "echo -n 'Building image... '",
            $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile --build-arg SOURCE_COMMIT={$this->git_commit} --progress plain -t {$this->application->uuid}:{$this->git_commit} {$this->workdir}"),
            "echo 'Done.'",
        ]);
        $this->executeNow([
            "echo -n 'Removing old container... '",
            $this->execute_in_builder("docker rm -f {$this->application->uuid} >/dev/null 2>&1"),
            "echo 'Done.'",
        ]);
        $this->executeNow([
            "echo -n 'Starting new container... '",
            $this->execute_in_builder("docker compose --project-directory {$this->workdir} up -d >/dev/null 2>&1"),
            "echo 'Done. 🎉'",
        ], setStatus: true);
        $this->executeNow([
            "docker stop -t 0 {$this->deployment_uuid} >/dev/null"
        ], setStatus: true);
    }

    private function execute_in_builder(string $command)
    {
        return "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }

    private function generate_docker_compose()
    {

        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->application->uuid => [
                    'image' => "{$this->application->uuid}:$this->git_commit",
                    'container_name' => $this->application->uuid,
                    'restart' => 'always',
                    'labels' => $this->set_labels_for_applications(),
                    'expose' => $this->application->ports_exposes,
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
        if (count($this->application->ports_mappings) > 0) {
            $docker_compose['services'][$this->application->uuid]['ports'] = $this->application->ports_mappings;
        }
        // if (count($volumes) > 0) {
        //     $docker_compose['services'][$this->application->uuid]['volumes'] = $volumes;
        // }
        // if (count($volume_names) > 0) {
        //     $docker_compose['volumes'] = $volume_names;
        // }
        return Yaml::dump($docker_compose);
    }

    private function generate_healthcheck_commands()
    {
        if (!$this->application->health_check_port) {
            $this->application->health_check_port = $this->application->ports_exposes[0];
        }
        if ($this->application->health_check_path) {
            $generated_healthchecks_commands = [
                "curl -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$this->application->health_check_port}{$this->application->health_check_path}"
            ];
        } else {
            $generated_healthchecks_commands = [];
            foreach ($this->application->ports_exposes as $key => $port) {
                $generated_healthchecks_commands = [
                    "curl -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$port}/"
                ];
                if (count($this->application->ports_exposes) != $key + 1) {
                    $generated_healthchecks_commands[] = '&&';
                }
            }
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

    private function executeNow(array $command, string $propertyName = null, bool $hideFromOutput = false, $setStatus = false)
    {
        $commandText = collect($command)->implode("\n");

        $this->activity->properties = $this->activity->properties->merge([
            'command' => $commandText,
        ]);
        $this->activity->save();

        $remoteProcess = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'hideFromOutput' => $hideFromOutput,
            'setStatus' => $setStatus,
        ]);

        if ($propertyName) {
            $result = $remoteProcess();
            $this->activity->properties = $this->activity->properties->merge([
                $propertyName => trim($result->output()),
            ]);
            $this->activity->save();
        } else {
            $remoteProcess();
        }
    }
    private function gitImport()
    {
        $source_html_url = data_get($this->application, 'source.html_url');
        $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
        $source_html_url_host = $url['host'];
        $source_html_url_scheme = $url['scheme'];
        if ($this->application->source->getMorphClass() == 'App\Models\GithubApp') {
            if ($this->source->is_public) {
                return $this->execute_in_builder("git clone -q -b {$this->application->git_branch} {$this->source->html_url}/{$this->application->git_repository}.git {$this->workdir}");
            } else {
                $github_access_token = $this->generate_jwt_token_for_github();
                return $this->execute_in_builder("git clone -q -b {$this->application->git_branch} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git {$this->workdir}");
            }
        }
    }
    private function nixpacks_build_cmd()
    {
        if (str_starts_with($this->application->base_image, 'apache') || str_starts_with($this->application->base_image, 'nginx')) {
            // @TODO: Add static site builds
        } else {
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
        }
        return $this->execute_in_builder($nixpacks_command);
    }
}
