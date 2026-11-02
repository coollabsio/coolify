<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\EnsureNodeWorkloadDnsNames;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates a cluster application that Coolify builds from a public Git
 * repository. It has no revision until the first build succeeds. The first
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
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }

        $registryIsNew = ! $cluster->registry()->exists();
        $workload = DB::transaction(function () use ($project, $environment, $cluster, $sourceType, $repository, $branch, $targetNode, $name, $baseDirectory, $dockerfileLocation): NodeWorkload {
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

    /** `https://github.com/acme/web-app.git` becomes `web-app`. */
    public static function defaultName(string $repository): string
    {
        $path = (string) parse_url($repository, PHP_URL_PATH);
        $name = preg_replace('/\.git$/', '', basename(rtrim($path, '/'))) ?: 'app';

        return str($name)->slug()->limit(60, '')->toString() ?: 'app';
    }
}
