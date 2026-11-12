<?php

namespace App\Traits;

use App\Actions\Node\Build\CreateClusterGitWorkload;
use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Actions\Node\Build\ResolveGitCommit;
use App\Enums\NodeWorkloadSourceType;
use App\Livewire\Project\New\DockerImage;
use App\Models\GithubApp;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The cluster part of the v4 private repository pages: the deployment target,
 * the build options, and the create step. The page supplies the repository
 * and its GitHub App or deploy key.
 */
trait CreatesClusterGitApplication
{
    public string $deploymentTarget = '';

    /** @var array<int, array{value: string, label: string, disabled: bool}> */
    public array $deploymentTargets = [];

    public string $build_pack = 'railpack';

    public ?string $base_directory = '/';

    public string $dockerfileLocation = 'Dockerfile';

    public array $parameters = [];

    protected function mountClusterTarget(): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->parameters = get_route_parameters();
        $this->deploymentTargets = DockerImage::clusterDeploymentTargets();
        $node = request()->query('server');
        $cluster = request()->query('cluster');
        $this->deploymentTarget = is_string($node) && $node !== '' ? 'node:'.$node
            : (is_string($cluster) && $cluster !== '' ? 'cluster:'.$cluster : '');
    }

    /** The project of the page, from the team of the session; the source must belong to its team. */
    protected function clusterProject(): Project
    {
        return Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
    }

    protected function authorizeClusterCredentials(): void
    {
        $this->authorize('create', NodeWorkload::class);
        $this->authorize('useTeamCredentials');
    }

    /** @return array<string, mixed> */
    protected function clusterBuildRules(): array
    {
        return [
            'deploymentTarget' => ['required', 'string', 'regex:/^(cluster|node):.+$/'],
            'build_pack' => ['required', Rule::enum(NodeWorkloadSourceType::class)->only(NodeWorkloadSourceType::gitBuildPacks())],
            'base_directory' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'dockerfileLocation' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
        ];
    }

    /**
     * Reads the branch with the credential first, so a missing access fails here and not at the first build.
     *
     * @param  string  $errorField  The field that shows a Git or create error.
     */
    protected function createClusterGitApplication(string $repository, string $branch, ?GithubApp $githubApp, ?PrivateKey $privateKey, string $errorField)
    {
        $project = $this->clusterProject();
        $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
        $this->authorizeClusterCredentials();
        [$targetType, $targetUuid] = explode(':', $this->deploymentTarget, 2);
        [$cluster, $targetNode] = DockerImage::resolveClusterTarget($project, $targetType, $targetUuid);

        try {
            $unsaved = new NodeWorkload(['team_id' => $project->team_id, 'github_app_id' => $githubApp?->id, 'private_key_id' => $privateKey?->id]);
            ResolveGitCommit::run($repository, $branch, IssueNodeGitCredential::run($unsaved));
            $workload = CreateClusterGitWorkload::run(
                $project,
                $environment,
                $cluster,
                NodeWorkloadSourceType::from($this->build_pack),
                $repository,
                $branch,
                auth()->user(),
                $targetNode,
                null,
                $this->base_directory,
                $this->dockerfileLocation,
                $githubApp,
                $privateKey,
            );
        } catch (Throwable $exception) {
            $this->addError($errorField, $exception->getMessage());

            return null;
        }

        // Like v4 applications: creating does not deploy. The user deploys from the application page.
        return redirectRoute($this, 'project.cluster-application.show', [
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
            'workload_uuid' => $workload->uuid,
        ]);
    }
}
