<?php

namespace App\Actions\Node;

use App\Enums\NodeRole;
use App\Models\Application;
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
        ?string $name = null,
    ): array {
        $image = $this->qualifiedImage($image);
        $name = filled($name) ? trim($name) : null;
        if ($environment->project_id !== $project->id) {
            throw new RuntimeException('The environment does not belong to this project.');
        }
        if ($cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The cluster does not belong to this project team.');
        }
        if (! $requestedBy->isAdminOfTeam($project->team_id)) {
            throw new RuntimeException('The user cannot deploy resources for this project team.');
        }
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }

        return DB::transaction(function () use ($project, $environment, $cluster, $image, $requestedBy, $targetNode, $name): array {
            $availableNodes = $cluster->nodes()
                ->where('is_usable', true)
                ->whereIn('role', [NodeRole::WORKER, NodeRole::CONTROLLER_WORKER])
                ->onDeployableClusterNetwork()
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
                    ? 'The cluster has no workload server that can accept a deployment.'
                    : 'The selected server is not available in this cluster.');
            }
            EnsureNodeAcceptsDeployment::run($node);

            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => $name ?? self::uniqueName($environment, self::defaultName($image)),
            ]);
            $revision = $workload->createRevision($image, ['restart_policy' => 'unless-stopped']);
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

    /**
     * A readable application name from an image reference: the repository basename without
     * registry, tag, or digest. `ghcr.io/acme/api@sha256:…` becomes `api`.
     */
    public static function defaultName(string $image): string
    {
        $repository = str(trim($image))->before('@')->toString();
        $lastSlash = strrpos($repository, '/');
        $lastColon = strrpos($repository, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $repository = substr($repository, 0, $lastColon);
        }
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', str($repository)->afterLast('/')->toString()));
        $name = trim($name, '-._');
        if ($name === '') {
            return 'application';
        }

        // Application names need at least three characters, like v4 application names.
        return mb_strlen($name) < 3 ? $name.'-app' : mb_substr($name, 0, 200);
    }

    /** Adds `-2`, `-3`, … while another application in the environment uses the name. */
    public static function uniqueName(Environment $environment, string $name): string
    {
        $usedNames = NodeWorkload::query()
            ->where('environment_id', $environment->id)
            ->where('name', 'like', $name.'%')
            ->pluck('name')
            ->merge(Application::query()
                ->where('environment_id', $environment->id)
                ->where('name', 'like', $name.'%')
                ->pluck('name'))
            ->map(fn (string $usedName): string => mb_strtolower($usedName))
            ->flip();

        $candidate = $name;
        for ($suffix = 2; $usedNames->has(mb_strtolower($candidate)); $suffix++) {
            $candidate = $name.'-'.$suffix;
        }

        return $candidate;
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
