<?php

namespace App\Actions\Node;

use App\Enums\NodeRole;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateClusterDockerImageWorkload
{
    use AsAction;

    /** @return array{workload: NodeWorkload, revision: NodeWorkloadRevision, operation: NodeOperation} */
    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        string $image,
        User $requestedBy,
        ?Node $targetNode = null,
    ): array {
        $image = $this->qualifiedImage($image);
        if ($environment->project_id !== $project->id) {
            throw new RuntimeException('The environment does not belong to this project.');
        }
        if ($cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The cluster does not belong to this project team.');
        }
        if (! $requestedBy->isAdminOfTeam($project->team_id)) {
            throw new RuntimeException('The user cannot deploy resources for this project team.');
        }
        if ($cluster->network_status !== 'active') {
            throw new RuntimeException('The cluster network is not ready.');
        }

        return DB::transaction(function () use ($project, $environment, $cluster, $image, $requestedBy, $targetNode): array {
            $availableNodes = $cluster->nodes()
                ->where('is_usable', true)
                ->whereIn('role', [NodeRole::WORKER, NodeRole::CONTROLLER_WORKER])
                ->with('cluster')
                ->withCount('workloads')
                ->orderBy('workloads_count')
                ->orderBy('id');
            $node = $targetNode === null
                ? $availableNodes->get()->first(function (Node $candidate): bool {
                    try {
                        EnsureNodeAcceptsDeployment::run($candidate);

                        return true;
                    } catch (\DomainException) {
                        return false;
                    }
                })
                : $availableNodes->whereKey($targetNode->id)->first();
            if ($node === null) {
                throw new RuntimeException($targetNode === null
                    ? 'The cluster has no workload Node that can accept a deployment.'
                    : 'The selected Node is not available in this cluster.');
            }
            EnsureNodeAcceptsDeployment::run($node);

            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => 'docker-image-'.new_public_id(),
            ]);
            $configuration = ['restart_policy' => 'unless-stopped'];
            $revision = $workload->revisions()->create([
                'image' => $image,
                'configuration' => $configuration,
                'configuration_hash' => hash('sha256', json_encode([
                    'image' => $image,
                    'configuration' => $configuration,
                ], JSON_THROW_ON_ERROR)),
            ]);
            $workload->nodes()->attach($node);
            EnsureNodeWorkloadDnsNames::run($node);
            $deployment = CreateDeploymentOperation::run($node, $revision, $requestedBy);

            return [
                'workload' => $workload->refresh(),
                'revision' => $revision,
                'operation' => $deployment['operation'],
            ];
        });
    }

    private function qualifiedImage(string $image): string
    {
        $name = str($image)->before('@')->toString();
        $lastSlash = strrpos($name, '/');
        $lastColon = strrpos($name, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $name = substr($name, 0, $lastColon);
        }
        $firstSegment = str($name)->before('/')->toString();
        if (str_contains($firstSegment, '.') || str_contains($firstSegment, ':') || $firstSegment === 'localhost') {
            return $image;
        }

        return str_contains($name, '/') ? 'docker.io/'.$image : 'docker.io/library/'.$image;
    }
}
