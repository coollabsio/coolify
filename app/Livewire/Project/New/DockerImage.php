<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Exception;
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
        $stringable = str($this->dockerImage)->before(':');
        $tag = str($this->dockerImage)->contains(':') ? str($this->dockerImage)->after(':') : 'latest';
        $destination_uuid = $this->query['destination'];
        $destination = StandaloneDocker::query()->where('uuid', $destination_uuid)->first();
        if (! $destination) {
            $destination = SwarmDocker::query()->where('uuid', $destination_uuid)->first();
        }
        if (! $destination) {
            throw new Exception('Destination not found. What?!');
        }
        $destination_class = $destination->getMorphClass();

        $project = Project::query()->where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();
        $application = Application::query()->create([
            'name' => 'docker-image-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerimage',
            'ports_exposes' => 80,
            'docker_registry_image_name' => $stringable,
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
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.new.docker-image');
    }
}
