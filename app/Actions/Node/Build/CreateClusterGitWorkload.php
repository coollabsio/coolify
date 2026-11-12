<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\EnsureNodeWorkloadDnsNames;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates a cluster application that Coolify builds from a Git repository:
 * a public one, or a private one through a GitHub App or a deploy key of the
 * project team (decision 0011). It has no revision until the first build succeeds. The first
 * Git application of a cluster creates its registry; the next network
 * revision starts zot and BuildKit on the Nodes.
 */
class CreateClusterGitWorkload
{
    use AsAction;

    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        NodeWorkloadSourceType $sourceType,
        string $repository,
        string $branch,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $name = null,
        ?string $baseDirectory = null,
        ?string $dockerfileLocation = null,
        ?GithubApp $githubApp = null,
        ?PrivateKey $privateKey = null,
    ): NodeWorkload {
        if (! $sourceType->builds()) {
            throw new RuntimeException('A Git application needs a build type.');
        }
        if ($environment->project_id !== $project->id || $cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The environment or cluster does not belong to this project.');
        }
        if (! $requestedBy->isAdminOfTeam($project->team_id)) {
            throw new RuntimeException('The user cannot deploy resources for this project team.');
        }
        self::ensureSource($project, $repository, $githubApp, $privateKey);
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }

        $registryIsNew = ! $cluster->registry()->exists();
        $workload = DB::transaction(function () use ($project, $environment, $cluster, $sourceType, $repository, $branch, $targetNode, $name, $baseDirectory, $dockerfileLocation, $githubApp, $privateKey): NodeWorkload {
            $node = CreateClusterDockerImageWorkload::placementNode($cluster, $targetNode);
            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => filled($name) ? trim($name) : CreateClusterDockerImageWorkload::uniqueName($environment, self::defaultName($repository)),
                'desired_state' => NodeWorkloadDesiredState::STOPPED,
                'source_type' => $sourceType,
                'git_repository' => $repository,
                'git_branch' => $branch,
                'github_app_id' => $githubApp?->id,
                'private_key_id' => $privateKey?->id,
                'base_directory' => filled($baseDirectory) ? trim($baseDirectory, ' /') : null,
                'dockerfile_location' => $sourceType === NodeWorkloadSourceType::DOCKERFILE && filled($dockerfileLocation) ? ltrim(trim($dockerfileLocation), '/') : null,
            ]);
            $workload->nodes()->attach($node);
            EnsureNodeWorkloadDnsNames::run($node);
            EnsureNodeRegistry::run($cluster);

            return $workload->refresh();
        });
        if ($registryIsNew) {
            QueueNodeClusterNetworkRevision::run($cluster);
        }

        return $workload;
    }

    /**
     * The source must belong to the project team, and the stored URL never carries a credential:
     * a token reads an https:// URL, a deploy key an SSH URL.
     */
    private static function ensureSource(Project $project, string $repository, ?GithubApp $githubApp, ?PrivateKey $privateKey): void
    {
        if ($githubApp !== null && $privateKey !== null) {
            throw new RuntimeException('Choose a GitHub App or a deploy key, not both.');
        }
        $isHttps = str_starts_with($repository, 'https://');
        if ($isHttps && (parse_url($repository, PHP_URL_USER) !== null || parse_url($repository, PHP_URL_PASS) !== null)) {
            throw new RuntimeException('Remove the user name and password from the repository URL.');
        }
        if ($githubApp !== null && (! $isHttps || ! IssueNodeGitCredential::githubApps($project->team_id)->whereKey($githubApp->id)->exists())) {
            throw new RuntimeException('The GitHub App is not available to this project team.');
        }
        if ($privateKey !== null && ($isHttps || ! IssueNodeGitCredential::privateKeys($project->team_id)->whereKey($privateKey->id)->exists())) {
            throw new RuntimeException('The deploy key is not available to this project team, or the repository URL is not an SSH URL.');
        }
        if ($githubApp === null && $privateKey === null && ! $isHttps) {
            throw new RuntimeException('A public repository needs an https:// URL.');
        }
    }

    /** `https://github.com/acme/web-app.git` becomes `web-app`. */
    public static function defaultName(string $repository): string
    {
        $path = (string) parse_url($repository, PHP_URL_PATH);
        $name = preg_replace('/\.git$/', '', basename(rtrim($path, '/'))) ?: 'app';

        return str($name)->slug()->limit(60, '')->toString() ?: 'app';
    }
}
