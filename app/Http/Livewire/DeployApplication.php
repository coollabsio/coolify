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

    private function execute_in_builder(string $command)
    {
        return $this->command[] = "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }
    private function start_builder_container()
    {
        $this->command[] = "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder >/dev/null 2>&1";
    }
    private function generate_docker_compose(mixed $application)
    {
        return Yaml::dump([
            'version' => '3.8',
            'services' => [
                $application->uuid => [
                    'image' => "{$application->uuid}:TAG",
                    'container_name' => $application->uuid,
                    'restart' => 'always',
                ]
            ]
        ]);
    }
    public function deploy()
    {
        $coolify_instance_settings = CoolifyInstanceSettings::find(1);
        $application = Application::where('uuid', $this->application_uuid)->first();
        $destination = $application->destination->getMorphClass()::where('id', $application->destination->id)->first();
        $source = $application->source->getMorphClass()::where('id', $application->source->id)->first();

        // Get Wildcard Domain
        $project_wildcard_domain = data_get($application, 'environment.project.settings.wildcard_domain');
        $global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
        $wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);

        // Set wildcard domain
        if (!$application->settings->is_bot && !$application->fqdn && $wildcard_domain) {
            $application->fqdn = $application->uuid . '.' . $wildcard_domain;
            $application->save();
        }
        $workdir = "/artifacts/{$this->deployment_uuid}";

        // Start build process
        $docker_compose_base64 = base64_encode($this->generate_docker_compose($application));
        $this->command[] = "echo 'Starting deployment of {$application->name} ({$application->uuid})'";
        $this->start_builder_container();
        $this->execute_in_builder("git clone -b {$application->git_branch} {$source->html_url}/{$application->git_repository}.git {$workdir}");

        // Export git commit to a file
        $this->execute_in_builder("cd {$workdir} && git rev-parse HEAD > {$workdir}/.git-commit");

        // Set TAG in docker-compose.yml
        $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$workdir}/docker-compose.yml");
        $this->execute_in_builder("sed -i \"s/TAG/$(cat {$workdir}/.git-commit)/g\" {$workdir}/docker-compose.yml");
        $this->execute_in_builder("cat {$workdir}/docker-compose.yml");

        if (str_starts_with($application->base_image, 'apache') || str_starts_with($application->base_image, 'nginx')) {
            // @TODO: Add static site builds
        } else {
            $nixpacks_command = "nixpacks build -o {$workdir} --no-error-without-start";
            if ($application->install_command) {
                $nixpacks_command .= " --install-cmd '{$application->install_command}'";
            }
            if ($application->build_command) {
                $nixpacks_command .= " --build-cmd '{$application->build_command}'";
            }
            if ($application->start_command) {
                $nixpacks_command .= " --start-cmd '{$application->start_command}'";
            }
            $nixpacks_command .= " {$workdir}";
            $this->execute_in_builder($nixpacks_command);
            $this->execute_in_builder("cp {$workdir}/.nixpacks/Dockerfile {$workdir}/Dockerfile");
            $this->execute_in_builder("rm -f {$workdir}/.nixpacks/Dockerfile");
        }
        $this->execute_in_builder("docker build -f {$workdir}/Dockerfile --build-arg SOURCE_COMMIT=$(cat {$workdir}/.git-commit) --progress plain -t {$application->uuid}:$(cat {$workdir}/.git-commit) {$workdir}");
        $this->execute_in_builder("docker compose --project-directory {$workdir} up -d");
        $this->command[] = "docker stop -t 0 {$this->deployment_uuid} >/dev/null";
        $this->activity = remoteProcess($this->command, $destination->server, $this->deployment_uuid, $application);

        $currentUrl = url()->previous();
        $deploymentUrl = "$currentUrl/deployment/$this->deployment_uuid";
        return redirect($deploymentUrl);
    }
    public function cancel()
    {
        // dd($this->deployment_uuid);
        // $jobs = DB::table('jobs')->get();
        // foreach ($jobs as $job) {
        //     // Decode the job payload
        //     $jobPayload = json_decode($job->payload, true);
        //     if (str_contains($jobPayload['data']['command'], $this->deployment_uuid)) {
        //         dd($jobPayload['data']['command']);
        //     }
        // }
    }
    public function render()
    {
        return view('livewire.deploy-application');
    }
}
