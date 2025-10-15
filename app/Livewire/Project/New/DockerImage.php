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

    /**
     * Auto-parse image name when user pastes a complete Docker image reference
     * Examples:
     * - nginx:stable-alpine3.21-perl@sha256:4e272eef...
     * - ghcr.io/user/app:v1.2.3
     * - nginx@sha256:abc123...
     */
    public function updatedImageName(): void
    {
        if (empty($this->imageName)) {
            return;
        }

        // Don't auto-parse if user has already manually filled tag or sha256 fields
        if (! empty($this->imageTag) || ! empty($this->imageSha256)) {
            return;
        }

        // Only auto-parse if the image name contains a tag (:) or digest (@)
        if (! str_contains($this->imageName, ':') && ! str_contains($this->imageName, '@')) {
            return;
        }

        try {
            $parser = new DockerImageParser;
            $parser->parse($this->imageName);

            // Extract the base image name (without tag/digest)
            $baseImageName = $parser->getFullImageNameWithoutTag();

            // Only update if parsing resulted in different base name
            // This prevents unnecessary updates when user types just the name
            if ($baseImageName !== $this->imageName) {
                if ($parser->isImageHash()) {
                    // It's a SHA256 digest (takes priority over tag)
                    $this->imageSha256 = $parser->getTag();
                    $this->imageTag = '';
                } elseif ($parser->getTag() !== 'latest' || str_contains($this->imageName, ':')) {
                    // It's a regular tag (only set if not default 'latest' or explicitly specified)
                    $this->imageTag = $parser->getTag();
                    $this->imageSha256 = '';
                }

                // Update imageName to just the base name
                $this->imageName = $baseImageName;
            }
        } catch (\Exception $e) {
            // If parsing fails, leave the image name as-is
            // User will see validation error on submit
        }
    }

    public function submit()
    {
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
            // Strip 'sha256:' prefix if user pasted it
            $sha256Hash = preg_replace('/^sha256:/i', '', trim($this->imageSha256));
            $dockerImage = $this->imageName.'@sha256:'.$sha256Hash;
        } elseif ($this->imageTag) {
            $dockerImage = $this->imageName.':'.$this->imageTag;
        } else {
            $dockerImage = $this->imageName.':latest';
        }

        // Parse using DockerImageParser to normalize the image reference
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

        // Append @sha256 to image name if using digest and not already present
        $imageName = $parser->getFullImageNameWithoutTag();
        if ($parser->isImageHash() && ! str_ends_with($imageName, '@sha256')) {
            $imageName .= '@sha256';
        }

        // Determine the image tag based on whether it's a hash or regular tag
        $imageTag = $parser->isImageHash() ? 'sha256-'.$parser->getTag() : $parser->getTag();

        $application = Application::create([
            'name' => 'docker-image-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerimage',
            'ports_exposes' => 80,
            'docker_registry_image_name' => $imageName,
            'docker_registry_image_tag' => $imageTag,
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
