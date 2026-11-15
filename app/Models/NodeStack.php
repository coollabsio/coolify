<?php

namespace App\Models;

use App\Services\NodeStack\NodeStackComposeMapper;
use App\Services\NodeStack\NodeStackGitSource;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\ScopedByAccessGrants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A Docker Compose file on a cluster (decision 0023): one workload for each Compose service. Its
 * services reach each other by their bare names in the namespace `dns_label`, and the cluster
 * firewall allows all traffic between them.
 */
class NodeStack extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory, ScopedByAccessGrants;

    /** Namespaces that Sentinel and Coolify use for other names. */
    public const RESERVED_LABELS = ['default', 'nodes', 'cluster'];

    protected $guarded = [];

    protected $hidden = ['variables'];

    protected function casts(): array
    {
        return [
            'compose_parsed' => 'array',
            // The summary of the last change of the Compose file from Git; see SyncNodeStackCompose.
            'compose_changes' => 'array',
            // The generated SERVICE_* values, created once for the stack (passwords, users, URLs).
            'variables' => 'encrypted:array',
        ];
    }

    /** Whether the Compose file comes from a Git repository (step 2 of decision 0023), not from a template. */
    public function hasGitSource(): bool
    {
        return filled($this->git_repository);
    }

    /**
     * The directory of the Compose file in the repository, without leading or trailing `/`. A
     * build context is relative to it.
     */
    public function composeDirectory(): string
    {
        return NodeStackGitSource::composeDirectoryOf($this->base_directory, $this->compose_location);
    }

    /**
     * The configuration of the first revision of a service that builds its image: its command,
     * restart policy, and files, as CreateClusterStack stores them for an image service.
     *
     * @return array<string, mixed>
     */
    public function serviceConfiguration(string $service): array
    {
        $services = NodeStackComposeMapper::services($this->compose_parsed, $this->variables ?? [], $this->hasGitSource() ? $this->composeDirectory() : null);
        foreach ($services as $mapped) {
            if ($mapped['service'] === $service) {
                return NodeStackComposeMapper::revisionConfiguration($mapped);
            }
        }

        return ['restart_policy' => 'unless-stopped'];
    }

    /** `<label>.coolify.internal`, the DNS search domain of every service of the stack. */
    public function searchDomain(): string
    {
        return $this->dns_label.'.coolify.internal';
    }

    /**
     * The `depends_on` of each service: service => [dependency => condition]. A dependency on a
     * service that the stack does not run (a Compose profile) is left out.
     *
     * @return array<string, array<string, string>>
     */
    public function dependencies(): array
    {
        $services = $this->compose_parsed['services'] ?? [];
        $dependencies = [];
        foreach ($services as $name => $service) {
            if (filled($service['profiles'] ?? null)) {
                continue;
            }
            $dependencies[(string) $name] = array_filter(
                NodeStackComposeMapper::dependencies($service['depends_on'] ?? []),
                fn (string $condition, string $dependency): bool => isset($services[$dependency]) && blank($services[$dependency]['profiles'] ?? null),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return $dependencies;
    }

    /**
     * The services in `depends_on` order: every service after the services it depends on.
     *
     * @param  array<string, array<string, string>>|null  $dependencies  See dependencies().
     * @return list<string>
     *
     * @throws RuntimeException When services depend on each other.
     */
    public function deployOrder(?array $dependencies = null): array
    {
        $dependencies ??= $this->dependencies();
        $order = [];
        $state = [];
        $visit = function (string $service, array $path) use (&$visit, &$order, &$state, $dependencies): void {
            if (($state[$service] ?? null) === 'done') {
                return;
            }
            if (($state[$service] ?? null) === 'visiting') {
                $cycle = [...array_slice($path, (int) array_search($service, $path, true)), $service];
                throw new RuntimeException('The services '.implode(' -> ', $cycle).' depend on each other in a cycle.');
            }
            $state[$service] = 'visiting';
            foreach (array_keys($dependencies[$service] ?? []) as $dependency) {
                $visit((string) $dependency, [...$path, $service]);
            }
            $state[$service] = 'done';
            $order[] = $service;
        };
        foreach (array_keys($dependencies) as $service) {
            $visit((string) $service, []);
        }

        return $order;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function workloads(): HasMany
    {
        return $this->hasMany(NodeWorkload::class)->orderBy('id');
    }

    /** The volumes that services of the stack share (decision 0024). */
    public function volumes(): HasMany
    {
        return $this->hasMany(NodeVolume::class)->orderBy('id');
    }

    public function link(): ?string
    {
        $project = $this->project()->value('uuid');
        $environment = $this->environment()->value('uuid');
        if ($project === null || $environment === null) {
            return null;
        }

        return route('project.cluster-stack.show', ['project_uuid' => $project, 'environment_uuid' => $environment, 'stack_uuid' => $this->uuid]);
    }
}
