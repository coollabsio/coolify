<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DockerImage extends Component
{
    public string $dockerImage = '';

    public array $parameters;

    public array $query;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
    }

    public function submit()
    {
        $this->validate([
            'dockerImage' => 'required',
        ]);
        $image = str($this->dockerImage)->before(':');
        if (str($this->dockerImage)->contains(':')) {
            $tag = str($this->dockerImage)->after(':');
        } else {
            $tag = 'latest';
        }
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
        ray($image, $tag);
        $application = Application::create([
            'name' => 'docker-image-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerimage',
            'ports_exposes' => 80,
            'docker_registry_image_name' => $image,
            'docker_registry_image_tag' => $tag,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'health_check_enabled' => false,
        ]);

        $fqdn = generateFqdn($destination->server, $application->uuid);
        $application->update([
            'name' => 'docker-image-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_name' => $environment->name,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.new.docker-image');
    }
}
