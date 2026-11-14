<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Services\NodeStack\NodeStackComposeMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates a stack from a Compose file on one server of a cluster (decision 0023): one stopped
 * cluster application for each Compose service, with its image, command, variables, domains,
 * health check, volumes, and files. Like a v4 service, it starts with Deploy.
 */
class CreateClusterStack
{
    use AsAction;

    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        string $compose,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $name = null,
        ?string $template = null,
    ): NodeStack {
        if ($environment->project_id !== $project->id) {
            throw new RuntimeException('The environment does not belong to this project.');
        }
        if ($cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The cluster does not belong to this project team.');
        }
        if (! $requestedBy->isAdminOfTeam($project->team_id)) {
            throw new RuntimeException('The user cannot create resources for this project team.');
        }
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }
        $yaml = NodeStackCompose::parse($compose);
        $reason = NodeStackCompose::unavailableReason($yaml);
        if ($reason !== null) {
            throw new RuntimeException("This stack is not available on Nodes. {$reason}");
        }
        $name = filled($name) ? trim($name) : ($template ?? 'stack');

        return DB::transaction(function () use ($project, $environment, $cluster, $compose, $yaml, $requestedBy, $targetNode, $name, $template): NodeStack {
            $node = CreateClusterDockerImageWorkload::placementNode($cluster, $targetNode);
            NodeCluster::query()->whereKey($cluster->id)->lockForUpdate()->first();
            $stack = NodeStack::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'node_cluster_id' => $cluster->id,
                'name' => $name,
                'dns_label' => self::uniqueLabel($cluster, $name),
                'template' => $template,
                'compose_raw' => $compose,
                'compose_parsed' => $yaml,
                'variables' => [],
            ]);
            // Fails before any workload exists.
            $stack->deployOrder();

            $base = $cluster->generatedDomainBase();
            if ($base === null && Str::contains($compose, ['SERVICE_URL_', 'SERVICE_FQDN_'])) {
                throw new RuntimeException('Turn on ingress for a server of the cluster to generate the domains of this stack.');
            }
            $variables = NodeStackComposeMapper::variables($yaml, [], $stack->uuid, (string) $base, $cluster->supportsIngressTls() ? 'https' : 'http');
            $stack->update(['variables' => $variables]);

            foreach (NodeStackComposeMapper::services($yaml, $variables) as $service) {
                $this->createService($stack, $node, $service, $requestedBy);
            }
            EnsureNodeWorkloadDnsNames::run($node);

            return $stack->refresh();
        });
    }

    /**
     * @param  array{service: string, image: string, command: list<string>, environment: array<string, string>, routes: list<array<string, mixed>>, health_check: ?array, restart_policy: string, volumes: list<array{name: string, path: string}>, files: list<array<string, mixed>>, depends_on: array<string, string>, notes: list<string>}  $service
     */
    private function createService(NodeStack $stack, Node $node, array $service, User $requestedBy): NodeWorkload
    {
        $environment = Environment::query()->findOrFail($stack->environment_id);
        $workload = NodeWorkload::query()->create([
            'team_id' => $stack->team_id,
            'project_id' => $stack->project_id,
            'environment_id' => $stack->environment_id,
            'node_stack_id' => $stack->id,
            'stack_service' => $service['service'],
            'name' => CreateClusterDockerImageWorkload::uniqueName($environment, Str::limit($stack->name, 100, '').'-'.$service['service']),
            'source_type' => NodeWorkloadSourceType::IMAGE,
            'desired_state' => NodeWorkloadDesiredState::STOPPED,
            'stack_health_check' => $service['health_check'],
            'description' => $service['notes'] === [] ? null : mb_substr(implode(' ', array_unique($service['notes'])), 0, 255),
        ]);
        $workload->createRevision($service['image'], array_filter([
            'command' => $service['command'],
            'restart_policy' => $service['restart_policy'],
            'files' => $service['files'],
        ], fn ($value): bool => $value !== []));
        foreach ($service['environment'] as $key => $value) {
            $workload->environment_variables()->create([
                'key' => $key,
                'value' => $value,
                'is_literal' => true,
                'is_runtime' => true,
                'is_buildtime' => false,
                'is_preview' => false,
            ]);
        }
        $workload->nodes()->attach($node);
        $paths = [];
        foreach ($service['volumes'] as $volume) {
            if (isset($paths[$volume['path']])) {
                continue;
            }
            $paths[$volume['path']] = true;
            CreateNodeVolume::run($workload, $volume['name'], $volume['path']);
        }
        if ($service['routes'] !== []) {
            UpdateNodeWorkloadDomains::run($workload, $service['routes'], $requestedBy);
        }

        return $workload;
    }

    /**
     * A DNS label from the stack name that no other stack of the cluster uses: `<label>.coolify.internal`
     * is the search domain of the stack. `default`, `nodes`, and `cluster` are namespaces of other names.
     */
    public static function uniqueLabel(NodeCluster $cluster, string $name): string
    {
        $base = rtrim(Str::limit(Str::slug($name), 50, ''), '-');
        if ($base === '') {
            $base = 'stack';
        }
        $used = NodeStack::query()->withoutGlobalScopes()->where('node_cluster_id', $cluster->id)->pluck('dns_label')->flip();
        $label = $base;
        for ($suffix = 2; $used->has($label) || in_array($label, NodeStack::RESERVED_LABELS, true); $suffix++) {
            $label = $base.'-'.$suffix;
        }

        return $label;
    }
}
