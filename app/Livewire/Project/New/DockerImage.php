<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\DockerImageParser;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DockerImage extends Component
{
    public string $imageName = '';

    public string $imageTag = '';

    public string $imageSha256 = '';

    public array $parameters;

    public array $query;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
    }

    public function submit()
    {
        // Strip 'sha256:' prefix if user pasted it
        if ($this->imageSha256) {
            $this->imageSha256 = preg_replace('/^sha256:/i', '', trim($this->imageSha256));
        }

        // Remove @sha256 from image name if user added it
        if ($this->imageName) {
            $this->imageName = preg_replace('/@sha256$/i', '', trim($this->imageName));
        }

        $this->validate([
            'imageName' => ['required', 'string'],
            'imageTag' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'imageSha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        // Validate that either tag or sha256 is provided, but not both
        if ($this->imageTag && $this->imageSha256) {
            $this->addError('imageTag', 'Provide either a tag or SHA256 digest, not both.');
            $this->addError('imageSha256', 'Provide either a tag or SHA256 digest, not both.');

            return;
        }

        // Build the full Docker image string
        if ($this->imageSha256) {
            $dockerImage = $this->imageName.'@sha256:'.$this->imageSha256;
        } elseif ($this->imageTag) {
            $dockerImage = $this->imageName.':'.$this->imageTag;
        } else {
            $dockerImage = $this->imageName.':latest';
        }

        $parser = new DockerImageParser;
        $parser->parse($dockerImage);

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
        $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

        // Determine the image tag based on whether it's a hash or regular tag
        $imageTag = $parser->isImageHash() ? 'sha256-'.$parser->getTag() : $parser->getTag();

        // Append @sha256 to image name if using digest and not already present
        $imageName = $parser->getFullImageNameWithoutTag();
        if ($parser->isImageHash() && ! str_ends_with($imageName, '@sha256')) {
            $imageName .= '@sha256';
        }

        $application = Application::create([
            'name' => 'docker-image-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerimage',
            'ports_exposes' => 80,
            'docker_registry_image_name' => $imageName,
            'docker_registry_image_tag' => $parser->getTag(),
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'health_check_enabled' => false,
        ]);

        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
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
