<?php

namespace App\Actions\Node;

use App\Enums\AccessLevel;
use App\Enums\NodeRole;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Support\CpuArchitecture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateClusterDockerImageWorkload
{
    use AsAction;

    /**
     * Creates the application and places it on a server, without deploying it. It stays stopped until
     * the user clicks Deploy; CreateDeploymentOperation then sets the desired state to running.
     *
     * @return array{workload: NodeWorkload, revision: NodeWorkloadRevision}
     */
    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        string $image,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $name = null,
    ): array {
        $image = self::qualifiedImage($image);
        $name = filled($name) ? trim($name) : null;
        if ($environment->project_id !== $project->id) {
            throw new RuntimeException('The environment does not belong to this project.');
        }
        if ($cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The cluster does not belong to this project team.');
        }
        self::ensureMayCreate($requestedBy, $environment, $targetNode);
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }

        $architectures = ReadImageArchitectures::run($image);

        return DB::transaction(function () use ($project, $environment, $cluster, $image, $targetNode, $name, $architectures, $requestedBy): array {
            $node = self::placementNode($cluster, $targetNode, $architectures, $requestedBy);

            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => $name ?? self::uniqueName($environment, self::defaultName($image)),
                'desired_state' => NodeWorkloadDesiredState::STOPPED,
            ]);
            $revision = $workload->createRevision($image, ['restart_policy' => 'unless-stopped']);
            $workload->nodes()->attach($node);
            EnsureNodeWorkloadDnsNames::run($node);

            return [
                'workload' => $workload->refresh(),
                'revision' => $revision,
            ];
        });
    }

    /**
     * The rule of a v4 resource for a cluster resource: the user may create resources in the
     * environment (`createAnyResource`), and may place resources on the selected server
     * (`NodePolicy::placeResource`). Automatic placement uses only such servers; see placementNode().
     *
     * @throws RuntimeException
     */
    public static function ensureMayCreate(User $user, Environment $environment, ?Node $targetNode): void
    {
        if (! Gate::forUser($user)->allows('createAnyResource', [$environment])) {
            throw new RuntimeException('You cannot create resources in this environment. You need write access on the environment.');
        }
        if ($targetNode !== null) {
            $placement = Gate::forUser($user)->inspect('placeResource', $targetNode);
            if ($placement->denied()) {
                throw new RuntimeException((string) $placement->message());
            }
        }
    }

    /**
     * The worker Node that a new cluster application runs on: the selected one, or the
     * usable worker with the fewest workloads that accepts a deployment. With the CPU
     * architectures of the image (ReadImageArchitectures), only a Node of one of them.
     *
     * @param  list<string>|null  $architectures  Null when they are unknown: every Node fits.
     * @param  ?User  $placedBy  The user of the placement. Automatic placement uses only servers where this user may place resources.
     */
    public static function placementNode(NodeCluster $cluster, ?Node $targetNode, ?array $architectures = null, ?User $placedBy = null): Node
    {
        $availableNodes = $cluster->nodes()
            ->where('is_usable', true)
            ->whereIn('role', [NodeRole::WORKER, NodeRole::CONTROLLER_WORKER])
            ->onDeployableClusterNetwork()
            ->with('cluster')
            ->withCount('workloads')
            ->orderBy('workloads_count')
            ->orderBy('id');
        if ($targetNode !== null) {
            $node = $availableNodes->whereKey($targetNode->id)->first()
                ?? throw new RuntimeException('The selected server is not available in this cluster.');
            $mismatch = CpuArchitecture::mismatch($node, $architectures);
            if ($mismatch !== null) {
                throw new RuntimeException("{$mismatch} Select a server with one of these architectures.");
            }
        } else {
            $fitting = $availableNodes->get()
                ->filter(fn (Node $candidate): bool => CpuArchitecture::mismatch($candidate, $architectures) === null)
                ->filter(fn (Node $candidate): bool => $placedBy === null || Gate::forUser($placedBy)->allows('placeResource', $candidate));
            $node = $fitting->first(function (Node $candidate): bool {
                try {
                    EnsureNodeAcceptsDeployment::run($candidate);

                    return true;
                } catch (\DomainException) {
                    return false;
                }
            }) ?? throw new RuntimeException($fitting->isEmpty() && $architectures !== null
                ? 'The cluster has no workload server with a CPU architecture of the image ('.(implode(', ', $architectures) ?: 'none in common').'). Coolify does not emulate another architecture.'
                : ($placedBy !== null && ! app(AccessResolver::class)->allowsTeamWide($placedBy, AccessLevel::Write, (int) $cluster->team_id)
                    ? 'The cluster has no workload server where you may place resources that can accept a deployment. You need write access on a resource that runs on the server.'
                    : 'The cluster has no workload server that can accept a deployment.'));
        }
        EnsureNodeAcceptsDeployment::run($node);

        return $node;
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
        $usedNames = app(AccessResolver::class)->withoutVisibilityScope(fn () => NodeWorkload::query()
            ->where('environment_id', $environment->id)
            ->where('name', 'like', $name.'%')
            ->pluck('name')
            ->merge(Application::query()
                ->where('environment_id', $environment->id)
                ->where('name', 'like', $name.'%')
                ->pluck('name')))
            ->map(fn (string $usedName): string => mb_strtolower($usedName))
            ->flip();

        $candidate = $name;
        for ($suffix = 2; $usedNames->has(mb_strtolower($candidate)); $suffix++) {
            $candidate = $name.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * Adds the Docker Hub registry to an image reference without a registry, and `:latest` to one
     * without a tag or digest, as Podman reports the image in the inventory. Otherwise a deploy
     * cannot match the running container to its revision.
     */
    public static function qualifiedImage(string $image): string
    {
        $name = str($image)->before('@')->toString();
        $lastSlash = strrpos($name, '/');
        $lastColon = strrpos($name, ':');
        $tagged = str_contains($image, '@') || ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash));
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $name = substr($name, 0, $lastColon);
        }
        $image = $tagged ? $image : $image.':latest';
        $firstSegment = str($name)->before('/')->toString();
        if (str_contains($firstSegment, '.') || str_contains($firstSegment, ':') || $firstSegment === 'localhost') {
            return $image;
        }

        return str_contains($name, '/') ? 'docker.io/'.$image : 'docker.io/library/'.$image;
    }
}
