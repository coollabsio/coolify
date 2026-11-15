<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\CreateClusterGitWorkload;
use App\Actions\Node\Build\EnsureNodeRegistry;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Services\NodeStack\NodeStackComposeMapper;
use App\Services\NodeStack\NodeStackGitSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates a stack from a Compose file on one server of a cluster (decision 0023): one stopped
 * cluster application for each Compose service, with its image, command, variables, domains,
 * health check, volumes, and files. Like a v4 service, it starts with Deploy.
 *
 * A Compose file from Git (step 2) keeps its source on the stack. A service with `build:` is a
 * Dockerfile application of the same repository: it has no revision until its first build, and
 * the first one of a cluster creates the registry, as for a Git application.
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
        ?NodeStackGitSource $git = null,
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
        if ($git !== null) {
            CreateClusterGitWorkload::ensureSource($project, $git->repository, $git->githubApp, $git->privateKey);
        }
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }
        $yaml = NodeStackCompose::parse($compose);
        $composeDirectory = $git?->composeDirectory();
        $reason = NodeStackCompose::unavailableReason($yaml, $cluster->supportsUnderscoreNames(), $composeDirectory, $cluster->supportsPublishedPorts());
        if ($reason !== null) {
            throw new RuntimeException("This stack is not available on Nodes. {$reason}");
        }
        $name = filled($name) ? trim($name) : ($template ?? ($git !== null ? CreateClusterGitWorkload::defaultName($git->repository) : 'stack'));
        $registryIsNew = ! $cluster->registry()->exists();

        $stack = DB::transaction(function () use ($project, $environment, $cluster, $compose, $yaml, $requestedBy, $targetNode, $name, $template, $git, $composeDirectory): NodeStack {
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
                ...($git === null ? [] : [
                    'git_repository' => $git->repository,
                    'git_branch' => $git->branch,
                    'github_app_id' => $git->githubApp?->id,
                    'private_key_id' => $git->privateKey?->id,
                    'base_directory' => NodeStackGitSource::repositoryPath($git->baseDirectory),
                    'compose_location' => '/'.NodeStackGitSource::repositoryPath($git->composeLocation),
                    'commit_sha' => $git->commitSha,
                ]),
            ]);
            // Fails before any workload exists.
            $stack->deployOrder();

            $variables = self::stackVariables($cluster, $stack, $yaml, $compose, []);
            $stack->update(['variables' => $variables]);

            $services = NodeStackComposeMapper::services($yaml, $variables, $composeDirectory);
            $conflict = self::portConflict($cluster, array_column($services, 'published_ports', 'service'));
            if ($conflict !== null) {
                throw new RuntimeException("This stack is not available on Nodes. {$conflict}");
            }
            $workloads = [];
            foreach ($services as $service) {
                $workloads[$service['service']] = self::createService($stack, $node, $service, $requestedBy);
            }
            self::createStackVolumes($stack, NodeStackComposeMapper::stackMounts($services), $workloads);
            EnsureNodeWorkloadDnsNames::run($node);
            if (in_array(true, array_map(fn (array $service): bool => $service['build'] !== null, $services), true)) {
                EnsureNodeRegistry::run($cluster);
            }

            return $stack->refresh();
        });
        // The next network revision starts zot and BuildKit on the Nodes.
        if ($registryIsNew && $cluster->registry()->exists()) {
            QueueNodeClusterNetworkRevision::run($cluster);
        }

        return $stack;
    }

    /**
     * @param  array{service: string, image: ?string, build: ?array{context: string, dockerfile: string, args: array<string, string>, target: ?string}, command: list<string>, environment: array<string, string>, routes: list<array<string, mixed>>, health_check: ?array, restart_policy: string, volumes: list<array{name: string, path: string}>, stack_volumes: list<array{key: string, name: string, path: string, read_only: bool}>, files: list<array<string, mixed>>, depends_on: array<string, string>, notes: list<string>}  $service
     */
    public static function createService(NodeStack $stack, Node $node, array $service, User $requestedBy): NodeWorkload
    {
        $environment = Environment::query()->findOrFail($stack->environment_id);
        $build = $service['build'];
        $workload = NodeWorkload::query()->create([
            'team_id' => $stack->team_id,
            'project_id' => $stack->project_id,
            'environment_id' => $stack->environment_id,
            'node_stack_id' => $stack->id,
            'stack_service' => $service['service'],
            'name' => CreateClusterDockerImageWorkload::uniqueName($environment, Str::limit($stack->name, 100, '').'-'.$service['service']),
            'desired_state' => NodeWorkloadDesiredState::STOPPED,
            'stack_health_check' => $service['health_check'],
            'description' => $service['notes'] === [] ? null : mb_substr(implode(' ', array_unique($service['notes'])), 0, 255),
            ...($build === null ? ['source_type' => NodeWorkloadSourceType::IMAGE] : [
                // The first build creates the first revision, with NodeStack::serviceConfiguration().
                'source_type' => NodeWorkloadSourceType::DOCKERFILE,
                'git_repository' => $stack->git_repository,
                'git_branch' => $stack->git_branch,
                'github_app_id' => $stack->github_app_id,
                'private_key_id' => $stack->private_key_id,
                'base_directory' => $build['context'] === '' ? null : $build['context'],
                'dockerfile_location' => $build['dockerfile'],
                'build_target' => $build['target'],
            ]),
        ]);
        if ($build === null) {
            $workload->createRevision((string) $service['image'], NodeStackComposeMapper::revisionConfiguration($service));
        }
        foreach (NodeStackComposeMapper::environmentVariables($service) as $key => $variable) {
            $workload->environment_variables()->create(['key' => $key, ...$variable, 'is_literal' => true, 'is_preview' => false]);
        }
        $workload->nodes()->attach($node);
        // A stack volume takes its path first; createStackVolumes() mounts it.
        $paths = array_fill_keys(array_column($service['stack_volumes'], 'path'), true);
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
        if ($service['published_ports'] !== []) {
            PublishNodeWorkloadPort::replace(NodeCluster::query()->findOrFail($stack->node_cluster_id), $workload, $service['published_ports']);
        }

        return $workload;
    }

    /**
     * Why the services cannot publish their ports in the cluster (decision 0016), or null: a
     * port that a Node uses, that another application publishes, or that two services publish.
     *
     * @param  array<string, list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>>  $portsByService
     * @param  list<int>  $replacedWorkloadIds  The workloads of the stack whose ports these lists replace.
     */
    public static function portConflict(NodeCluster $cluster, array $portsByService, array $replacedWorkloadIds = []): ?string
    {
        $owners = [];
        foreach ($portsByService as $service => $ports) {
            foreach ($ports as $port) {
                $label = PublishNodeWorkloadPort::label($port);
                $owner = $owners[$port['protocol'].'/'.$port['host_port']] ?? null;
                if ($owner !== null) {
                    return $owner === $service
                        ? "The service {$service} publishes {$label} twice."
                        : "The services {$owner} and {$service} both publish {$label}.";
                }
                $owners[$port['protocol'].'/'.$port['host_port']] = (string) $service;
                $reason = PublishNodeWorkloadPort::unavailableReason($cluster, $port, $replacedWorkloadIds);
                if ($reason !== null) {
                    return "The service {$service} publishes {$label}. {$reason}";
                }
            }
        }

        return null;
    }

    /**
     * The generated `SERVICE_*` values of the stack for a Compose file: the existing values stay,
     * and each new one is generated once.
     *
     * @param  array<string, mixed>  $yaml
     * @param  array<string, string>  $existing
     * @return array<string, string>
     */
    public static function stackVariables(NodeCluster $cluster, NodeStack $stack, array $yaml, string $compose, array $existing): array
    {
        $base = $cluster->generatedDomainBase();
        if ($base === null && Str::contains($compose, ['SERVICE_URL_', 'SERVICE_FQDN_'])) {
            throw new RuntimeException('Turn on ingress for a server of the cluster to generate the domains of this stack.');
        }

        return NodeStackComposeMapper::variables($yaml, $existing, $stack->uuid, (string) $base, $cluster->supportsIngressTls() ? 'https' : 'http');
    }

    /**
     * Mounts the volumes that services of the stack share (decision 0024): each mount mounts the
     * volume of its key, at its path, read-write or read-only. A key without a volume in
     * `$volumes` gets a new volume of the stack, with a name that no other volume of the stack
     * has. A new volume has no Node until the first deployment of one of its services creates it.
     *
     * @param  list<array{service: string, key: string, name: string, path: string, read_only: bool}>  $mounts  See NodeStackComposeMapper::stackMounts().
     * @param  array<string, NodeWorkload>  $workloads  By service.
     * @param  array<string, NodeVolume>  $volumes  The existing volumes of the stack, by key.
     */
    public static function createStackVolumes(NodeStack $stack, array $mounts, array $workloads, array $volumes = []): void
    {
        $names = NodeVolume::query()->where('node_stack_id', $stack->id)->pluck('name')->flip()->all();
        foreach ($mounts as $mount) {
            if (! isset($volumes[$mount['key']])) {
                $name = $mount['name'];
                for ($suffix = 2; isset($names[$name]); $suffix++) {
                    $name = $mount['name'].'-'.$suffix;
                }
                $names[$name] = true;
                $volumes[$mount['key']] = NodeVolume::query()->create([
                    'team_id' => $stack->team_id,
                    'node_stack_id' => $stack->id,
                    'name' => $name,
                ]);
            }
            NodeVolumeMount::query()->firstOrCreate(
                ['node_volume_id' => $volumes[$mount['key']]->id, 'node_workload_id' => $workloads[$mount['service']]->id],
                ['mount_path' => $mount['path'], 'read_only' => $mount['read_only']],
            );
        }
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
