<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class SimpleDockerfile extends Component
{
    public string $dockerfile = '';

    public array $parameters;

    public array $query;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->dockerfile = 'FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
';
        }
    }

    public function submit()
    {
        $this->validate([
            'dockerfile' => 'required',
        ]);
        $destination_uuid = $this->query['destination'];
        $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
        if (! $destination) {
            $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
        }
        if (! $destination) {
            throw new \Exception('Destination not found. What?!');
        }
        $destination_class = $destination->getMorphClass();

        $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();

        $port = get_port_from_dockerfile($this->dockerfile);
        if (! $port) {
            $port = 80;
        }
        $application = Application::create([
            'name' => 'dockerfile-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'dockerfile' => $this->dockerfile,
            'ports_exposes' => $port,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'health_check_enabled' => false,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);

        $fqdn = generateFqdn($destination->server, $application->uuid);
        $application->update([
            'name' => 'dockerfile-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        $application->parseHealthcheckFromDockerfile(dockerfile: collect(str($this->dockerfile)->trim()->explode("\n")), isInit: true);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_name' => $environment->name,
            'project_uuid' => $project->uuid,
        ]);
    }
}
