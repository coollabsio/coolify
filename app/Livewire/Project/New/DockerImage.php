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
    public ?string $registryUsername = null;
    public ?string $registryToken = null;
    public ?string $registryUrl = 'docker.io';
    public bool $useCustomRegistry = false;
    public array $parameters;
    public array $query;

    protected $rules = [
        'dockerImage' => 'required|string',
        'registryUsername' => 'required_if:useCustomRegistry,true|string|nullable',
        'registryToken' => 'required_if:useCustomRegistry,true|string|nullable',
        'registryUrl' => 'nullable|string',
        'useCustomRegistry' => 'boolean'
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->registryUrl = 'docker.io';
    }

    public function submit()
    {
        $this->validate([
            'dockerImage' => 'required',
            'registryUsername' => 'required_if:useCustomRegistry,true',
            'registryToken' => 'required_if:useCustomRegistry,true',
        ]);
        
        // Only save registry settings if useCustomRegistry is true
        if (!$this->useCustomRegistry) {
            $this->registryUsername = null;
            $this->registryToken = null;
            $this->registryUrl = 'docker.io';
        }
        
        $image = str($this->dockerImage)->before(':');
        $tag = str($this->dockerImage)->contains(':') ? 
            str($this->dockerImage)->after(':') : 
            'latest';

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
            'docker_use_custom_registry' => $this->useCustomRegistry,
            'docker_registry_url' => $this->registryUrl,
            'docker_registry_username' => $this->registryUsername,
            'docker_registry_token' => $this->registryToken,
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
