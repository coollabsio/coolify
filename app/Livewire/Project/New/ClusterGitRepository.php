<?php

namespace App\Livewire\Project\New;

use App\Actions\Node\Build\CreateClusterGitWorkload;
use App\Actions\Node\Build\ResolveGitCommit;
use App\Enums\NodeWorkloadSourceType;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

/** Creates a cluster application that Coolify builds from a public Git repository. */
class ClusterGitRepository extends Component
{
    use AuthorizesRequests;

    public string $repository = '';

    public string $branch = 'main';

    public string $buildPack = 'railpack';

    public string $baseDirectory = '';

    public string $dockerfileLocation = 'Dockerfile';

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
    }

    public function submit()
    {
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->validate([
            'deploymentTarget' => ['required', 'string', 'regex:/^(cluster|node):.+$/'],
            'repository' => ['required', 'string', 'max:2048', 'starts_with:https://', new ValidGitRepositoryUrl(allowSSH: false)],
            'branch' => ['required', 'string', 'max:255', new ValidGitBranch],
            'buildPack' => ['required', Rule::enum(NodeWorkloadSourceType::class)->only(NodeWorkloadSourceType::gitBuildPacks())],
            'baseDirectory' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'dockerfileLocation' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'name' => ValidationPatterns::nameRules(required: false),
        ], [
            'repository.starts_with' => 'Use the https:// URL of a public repository.',
        ]);

        $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
        $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
        $this->authorize('create', NodeWorkload::class);
        [$targetType, $targetUuid] = explode(':', $this->deploymentTarget, 2);
        [$cluster, $targetNode] = DockerImage::resolveClusterTarget($project, $targetType, $targetUuid);

        try {
            // Fails early when the repository is private, missing, or has no such branch.
            ResolveGitCommit::run($this->repository, $this->branch);
            $workload = CreateClusterGitWorkload::run(
                $project,
                $environment,
                $cluster,
                NodeWorkloadSourceType::from($this->buildPack),
                $this->repository,
                $this->branch,
                auth()->user(),
                $targetNode,
                $this->name,
                $this->baseDirectory,
                $this->dockerfileLocation,
            );
        } catch (RuntimeException $exception) {
            $this->addError('repository', $exception->getMessage());

            return;
        }

        // Like v4 applications: creating does not deploy. The user deploys from the application page.
        return redirectRoute($this, 'project.cluster-application.show', [
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
            'workload_uuid' => $workload->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.new.cluster-git-repository');
    }
}
