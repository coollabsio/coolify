<?php

namespace App\Http\Livewire;

use App\Models\Application;
use App\Models\CoolifyInstanceSettings;
use App\Models\Deployment;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DeployApplication extends Component
{
    public string $application_uuid;
    public $activity;
    protected string $deployment_uuid;
    protected array $command = [];

    private function execute_in_builder(string $command)
    {
        return $this->command[] = "docker exec {$this->deployment_uuid} sh -c '{$command}'";
    }
    private function start_builder_container()
    {
        // @TODO: Add --pull=always if the container is published to ghcr.io
        $this->command[] = "docker run -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock coolify-builder >/dev/null";
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
        $this->deployment_uuid = new Cuid2(10);
        $workdir = "/artifacts/{$this->deployment_uuid}";

        // Start build process
        $this->command[] = "echo 'Starting deployment of {$application->name} ({$application->uuid})'";
        $this->start_builder_container();
        $this->execute_in_builder('hostname');
        $this->execute_in_builder("git clone -b {$application->git_branch} {$source->html_url}/{$application->git_repository}.git {$workdir}");
        $this->execute_in_builder("ls -l {$workdir}");
        $this->command[] = "docker stop -t 0 {$this->deployment_uuid} >/dev/null";

        $this->activity = remoteProcess(implode("\n", $this->command), $destination->server->name);

        // Create Deployment
        Deployment::create([
            'uuid' => $this->deployment_uuid,
            'type_id' => $application->id,
            'type_type' => Application::class,
            'activity_log_id' => $this->activity->id,
        ]);
        // Redirect to deployment page
        return redirect()->route('project.deployment', [
            "deployment_uuid" => $this->deployment_uuid,
            "project_uuid" => $application->environment->project->uuid,
            "environment_name" => $application->environment->name,
            "application_uuid" => $application->uuid
        ]);
    }
    public function render()
    {
        return view('livewire.deploy-application');
    }
}
