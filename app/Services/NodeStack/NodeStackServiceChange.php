<?php

namespace App\Services\NodeStack;

use App\Models\NodeWorkload;

/**
 * What a new Compose file changes in one existing service of a stack, after the three-way rule of
 * NodeStackComposeDiff. Each field holds only what differs from the current workload.
 */
final readonly class NodeStackServiceChange
{
    /**
     * @param  ?array<string, mixed>  $previous  The service in the previous file; see NodeStackComposeMapper::services().
     * @param  array<string, mixed>  $next  The service in the new file.
     * @param  array<string, array{value: string, is_runtime: bool, is_buildtime: bool}>  $variables  Environment variables to create or update, by key.
     * @param  list<string>  $deletedVariables  Keys of environment variables to delete.
     * @param  ?array{image: string, configuration: array<string, mixed>}  $revision  A new latest revision, or null when it stays.
     * @param  array<string, mixed>  $attributes  Workload columns to update.
     * @param  ?list<array{scheme: string, host: string, port: int|string|null, path: string, noindex: bool}>  $routes  The new domains, or null when they stay.
     * @param  list<array{name: string, path: string}>  $volumes  Own volumes to create.
     * @param  list<string>  $keptVolumes  Paths of own volumes that left the file. Their data stays.
     * @param  ?list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>  $publishedPorts  The new published ports, or null when they stay.
     */
    public function __construct(
        public NodeWorkload $workload,
        public ?array $previous,
        public array $next,
        public array $variables = [],
        public array $deletedVariables = [],
        public ?array $revision = null,
        public array $attributes = [],
        public ?array $routes = null,
        public array $volumes = [],
        public array $keptVolumes = [],
        public ?array $publishedPorts = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->variables === []
            && $this->deletedVariables === []
            && $this->revision === null
            && $this->attributes === []
            && $this->routes === null
            && $this->volumes === []
            && $this->keptVolumes === []
            && $this->publishedPorts === null;
    }
}
