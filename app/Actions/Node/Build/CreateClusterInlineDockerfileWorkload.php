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
 * Creates a cluster application from a Dockerfile that the user pasted, the v4
 * "Dockerfile" resource. Coolify builds it with an empty context, so it has no
 * Git repository. Like a Git application, it has no revision until the first
 * build succeeds, and the first built application of a cluster creates its registry.
 */
class CreateClusterInlineDockerfileWorkload
{
    use AsAction;

    /** Sentinel accepts at most 128 KiB (`image.build.inline_dockerfile.v1`). */
    public const MAX_BYTES = 131_072;

    /** @return list<mixed> */
    public static function dockerfileRules(): array
    {
        return ['required', 'string', 'max:'.self::MAX_BYTES, 'not_regex:/\x00/'];
    }

    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        string $dockerfile,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $name = null,
    ): NodeWorkload {
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
        $workload = DB::transaction(function () use ($project, $environment, $cluster, $dockerfile, $targetNode, $name): NodeWorkload {
            $node = CreateClusterDockerImageWorkload::placementNode($cluster, $targetNode);
            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => filled($name) ? trim($name) : CreateClusterDockerImageWorkload::uniqueName($environment, 'dockerfile'),
                'desired_state' => NodeWorkloadDesiredState::STOPPED,
                'source_type' => NodeWorkloadSourceType::DOCKERFILE_INLINE,
                'dockerfile' => $dockerfile,
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
}
