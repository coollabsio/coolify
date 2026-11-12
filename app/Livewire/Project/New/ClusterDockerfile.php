<?php

namespace App\Livewire\Project\New;

use App\Actions\Node\Build\CreateClusterInlineDockerfileWorkload;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

/** Creates a cluster application from a pasted Dockerfile, the v4 "Dockerfile" resource on a cluster. */
class ClusterDockerfile extends Component
{
    use AuthorizesRequests;

    public string $dockerfile = '';

    public string $name = '';

    public string $deploymentTarget = '';

    /** @var array<int, array{value: string, label: string, disabled: bool}> */
    public array $deploymentTargets = [];

    public array $parameters;

    public function mount(): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->parameters = get_route_parameters();
        $query = request()->query();
        $this->deploymentTargets = DockerImage::clusterDeploymentTargets();
        $node = $query['server'] ?? null;
        $cluster = $query['cluster'] ?? null;
        $this->deploymentTarget = is_string($node) && $node !== '' ? 'node:'.$node
            : (is_string($cluster) && $cluster !== '' ? 'cluster:'.$cluster : '');
        if (isDev()) {
            $this->dockerfile = "FROM nginx\nEXPOSE 80\nCMD [\"nginx\", \"-g\", \"daemon off;\"]\n";
        }
    }

    public function submit()
    {
        $this->validate([
            'deploymentTarget' => ['required', 'string', 'regex:/^(cluster|node):.+$/'],
            'dockerfile' => CreateClusterInlineDockerfileWorkload::dockerfileRules(),
            'name' => ValidationPatterns::nameRules(required: false),
        ]);

        $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
        $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
        $this->authorize('create', NodeWorkload::class);
        [$targetType, $targetUuid] = explode(':', $this->deploymentTarget, 2);
        [$cluster, $targetNode] = DockerImage::resolveClusterTarget($project, $targetType, $targetUuid);

        try {
            $workload = CreateClusterInlineDockerfileWorkload::run($project, $environment, $cluster, $this->dockerfile, auth()->user(), $targetNode, $this->name);
        } catch (RuntimeException $exception) {
            $this->addError('dockerfile', $exception->getMessage());

            return;
        }

        return redirectRoute($this, 'project.cluster-application.show', [
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
            'workload_uuid' => $workload->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.new.cluster-dockerfile');
    }
}
