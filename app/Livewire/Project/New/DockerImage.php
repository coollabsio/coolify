<?php

namespace App\Livewire\Project\New;

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\Application;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Services\DockerImageParser;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DockerImage extends Component
{
    use AuthorizesRequests;

    public string $imageName = '';

    public string $imageTag = '';

    public string $imageSha256 = '';

    public string $deploymentTarget = '';

    /** @var array<int, array{value: string, label: string, disabled: bool}> */
    public array $deploymentTargets = [];

    public array $parameters;

    public array $query;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->loadDeploymentTargets();
    }

    public function loadDeploymentTargets(): void
    {
        $clusterUuid = $this->query['cluster'] ?? null;
        $nodeUuid = $this->query['node'] ?? null;
        $destinationUuid = $this->query['destination'] ?? null;
        $this->deploymentTarget = is_string($nodeUuid) && $nodeUuid !== ''
            ? 'node:'.$nodeUuid
            : (is_string($clusterUuid) && $clusterUuid !== ''
                ? 'cluster:'.$clusterUuid
                : (is_string($destinationUuid) && $destinationUuid !== '' ? 'destination:'.$destinationUuid : ''));

        $this->deploymentTargets = NodeCluster::query()
            ->where('team_id', currentTeam()->id)
            ->with(['nodes' => fn ($query) => $query
                ->where('is_usable', true)
                ->whereIn('role', ['worker', 'controller-worker'])
                ->orderBy('name')])
            ->withCount(['nodes as available_nodes_count' => fn ($query) => $query
                ->where('is_usable', true)
                ->whereIn('role', ['worker', 'controller-worker'])])
            ->orderBy('name')
            ->get()
            ->flatMap(function (NodeCluster $cluster): array {
                $ready = $cluster->network_status === 'active' && $cluster->available_nodes_count > 0;

                $targets = [[
                    'value' => 'cluster:'.$cluster->uuid,
                    'label' => $cluster->name.' — Automatic placement — '.($ready
                        ? $cluster->available_nodes_count.' available '.str('node')->plural($cluster->available_nodes_count)
                        : 'not ready'),
                    'disabled' => ! $ready,
                ]];
                if ($ready) {
                    foreach ($cluster->nodes as $node) {
                        $targets[] = [
                            'value' => 'node:'.$node->uuid,
                            'label' => $cluster->name.' — Node: '.$node->name,
                            'disabled' => false,
                        ];
                    }
                }

                return $targets;
            })
            ->values()
            ->all();

        if (is_string($destinationUuid) && $destinationUuid !== '') {
            $destination = find_resource_destination_for_current_team($destinationUuid);
            if ($destination !== null) {
                $this->deploymentTargets[] = [
                    'value' => 'destination:'.$destination->uuid,
                    'label' => $destination->server->name.' — Legacy server',
                    'disabled' => false,
                ];
            }
        }
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
        $this->authorize('create', Application::class);

        $this->validate([
            'deploymentTarget' => ['required', 'string', 'regex:/^(cluster|node|destination):.+$/'],
            'imageName' => ValidationPatterns::dockerImageNameRules(required: true),
            'imageTag' => ValidationPatterns::dockerImageTagRules(),
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

        [$targetType, $targetUuid] = explode(':', $this->deploymentTarget, 2);
        if (in_array($targetType, ['cluster', 'node'], true)) {
            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
            $this->authorize('create', NodeWorkload::class);
            $targetNode = $targetType === 'node'
                ? Node::query()
                    ->where('team_id', $project->team_id)
                    ->where('uuid', $targetUuid)
                    ->whereNotNull('node_cluster_id')
                    ->firstOrFail()
                : null;
            $cluster = $targetNode?->cluster ?? NodeCluster::query()
                ->where('team_id', $project->team_id)
                ->where('uuid', $targetUuid)
                ->firstOrFail();
            $deployment = CreateClusterDockerImageWorkload::run(
                $project,
                $environment,
                $cluster,
                $dockerImage,
                auth()->user(),
                $targetNode,
            );
            DeployNodeWorkloadJob::dispatch($deployment['operation']->id);

            return redirectRoute($this, 'project.cluster-application.show', [
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
                'workload_uuid' => $deployment['workload']->uuid,
            ]);
        }

        $destination = find_resource_destination_for_current_team($targetUuid);
        if (! $destination) {
            throw new \Exception('Destination not found.');
        }
        $destination_class = $destination->getMorphClass();
        $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
        $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

        // Append @sha256 to image name if using digest and not already present
        $imageName = $parser->getFullImageNameWithoutTag();
        if ($parser->isImageHash() && ! str_ends_with($imageName, '@sha256')) {
            $imageName .= '@sha256';
        }

        // Determine the image tag based on whether it's a hash or regular tag
        $imageTag = $parser->isImageHash() ? 'sha256-'.$parser->getTag() : $parser->getTag();

        $application = new Application([
            'name' => 'docker-image-'.new_public_id(),
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
        $application->save();

        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->update([
            'name' => 'docker-image-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        return redirectRoute($this, 'project.application.configuration', [
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
