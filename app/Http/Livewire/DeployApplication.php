<?php

namespace App\Http\Livewire;

use App\Models\Application;
use App\Models\CoolifyInstanceSettings;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

class DeployApplication extends Component
{
    public string $application_uuid;
    public $activity;
    protected string $deployment_uuid;
    protected array $command = [];
    protected Application $application;
    protected $destination;

    private function execute_in_builder(string $command)
    {
        return $this->command[] = "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }
    private function start_builder_container()
    {
        $this->command[] = "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder >/dev/null 2>&1";
    }
    private function generate_docker_compose()
    {
        return Yaml::dump([
            'version' => '3.8',
            'services' => [
                $this->application->uuid => [
                    'image' => "{$this->application->uuid}:TAG",
                    'expose' => $this->application->ports_exposes,
                    'container_name' => $this->application->uuid,
                    'restart' => 'always',
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
        ]);
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
    public function deploy()
    {
        $coolify_instance_settings = CoolifyInstanceSettings::find(1);
        $this->application = Application::where('uuid', $this->application_uuid)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
        $source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();
        // Get Wildcard Domain
        $project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
        $global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
        $wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);

        // Set wildcard domain
        if (!$this->application->settings->is_bot && !$this->application->fqdn && $wildcard_domain) {
            $this->application->fqdn = $this->application->uuid . '.' . $wildcard_domain;
            $this->application->save();
        }
        $workdir = "/artifacts/{$this->deployment_uuid}";

        // Start build process
        $docker_compose_base64 = base64_encode($this->generate_docker_compose($this->application));
        $this->command[] = "echo 'Starting deployment of {$this->application->name} ({$this->application->uuid})'";
        $this->start_builder_container();
        $this->execute_in_builder("git clone -b {$this->application->git_branch} {$source->html_url}/{$this->application->git_repository}.git {$workdir}");

        // Export git commit to a file
        $this->execute_in_builder("cd {$workdir} && git rev-parse HEAD > {$workdir}/.git-commit");
        $this->execute_in_builder("rm -fr {$workdir}/.git");

        // Create docker-compose.yml
        $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$workdir}/docker-compose.yml");
        // Set TAG in docker-compose.yml
        $this->execute_in_builder("sed -i \"s/TAG/$(cat {$workdir}/.git-commit)/g\" {$workdir}/docker-compose.yml");

        if (str_starts_with($this->application->base_image, 'apache') || str_starts_with($this->application->base_image, 'nginx')) {
            // @TODO: Add static site builds
        } else {
            $nixpacks_command = "nixpacks build -o {$workdir} --no-error-without-start";
            if ($this->application->install_command) {
                $nixpacks_command .= " --install-cmd '{$this->application->install_command}'";
            }
            if ($this->application->build_command) {
                $nixpacks_command .= " --build-cmd '{$this->application->build_command}'";
            }
            if ($this->application->start_command) {
                $nixpacks_command .= " --start-cmd '{$this->application->start_command}'";
            }
            $nixpacks_command .= " {$workdir}";
            $this->execute_in_builder($nixpacks_command);
            $this->execute_in_builder("cp {$workdir}/.nixpacks/Dockerfile {$workdir}/Dockerfile");
            $this->execute_in_builder("rm -f {$workdir}/.nixpacks/Dockerfile");
        }

        $this->execute_in_builder("docker build -f {$workdir}/Dockerfile --build-arg SOURCE_COMMIT=$(cat {$workdir}/.git-commit) --progress plain -t {$this->application->uuid}:$(cat {$workdir}/.git-commit) {$workdir}");
        $this->execute_in_builder("test -z \"$(docker ps --format '{{.State}}' --filter 'name={$this->application->uuid}')\" && docker rm -f {$this->application->uuid}");
        $this->execute_in_builder("docker compose --project-directory {$workdir} up -d");
        $this->command[] = "docker stop -t 0 {$this->deployment_uuid} >/dev/null";
        $this->activity = remoteProcess($this->command, $this->destination->server, $this->deployment_uuid, $this->application);

        $currentUrl = url()->previous();
        $deploymentUrl = "$currentUrl/deployment/$this->deployment_uuid";
        return redirect($deploymentUrl);
    }
    public function cancel()
    {
    }
    public function render()
    {
        return view('livewire.deploy-application');
    }
}
