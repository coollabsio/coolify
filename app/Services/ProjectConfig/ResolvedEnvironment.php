<?php

namespace App\Services\ProjectConfig;

/**
 * The desired state of one environment: overrides merged, resources in create order.
 */
final readonly class ResolvedEnvironment
{
    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @param  list<array{from: string, to: string}>  $moved
     * @param  list<string>  $unmanage
     * @param  list<array{to: string, uuid: string}>  $imports
     */
    public function __construct(
        public string $name,
        public string $branch,
        public array $resources,
        public array $moved,
        public array $unmanage,
        public array $imports,
        public int $formatVersion,
        public string $fileHash,
    ) {}

    /**
     * Fills the server of the link into resources that name no server.
     *
     * @param  array{server?: string, destination?: string}  $placement
     */
    public function withDefaultPlacement(array $placement): self
    {
        if (! isset($placement['server'])) {
            return $this;
        }
        $resources = array_map(function (array $resource) use ($placement): array {
            if (array_key_exists('server', $resource)) {
                return $resource;
            }
            $resource['server'] = $placement['server'];
            if (isset($placement['destination']) && ! array_key_exists('destination', $resource)) {
                $resource['destination'] = $placement['destination'];
            }

            return $resource;
        }, $this->resources);

        return new self($this->name, $this->branch, $resources, $this->moved, $this->unmanage, $this->imports, $this->formatVersion, $this->fileHash);
    }

    /**
     * The file as a one-time template: only its resources. Applications without a repository use the
     * repository that the user adds, so a fork or a copy of the repository builds itself.
     *
     * @param  array{repository: string, branch: string, github_app?: string, gitea_app?: string, deploy_key?: string}  $source
     */
    public function asTemplate(array $source): self
    {
        $resources = array_map(function (array $resource) use ($source): array {
            if ($resource['type'] !== 'application' || ($resource['build']['pack'] ?? null) === 'dockerimage' || isset($resource['source']['repository'])) {
                return $resource;
            }
            $current = (array) ($resource['source'] ?? []);
            $credential = array_intersect_key($current, array_flip(['github_app', 'gitlab_app', 'gitea_app', 'deploy_key'])) === []
                ? array_diff_key($source, array_flip(['repository', 'branch']))
                : [];
            $resource['source'] = ['repository' => $source['repository'], 'branch' => $current['branch'] ?? $source['branch']] + $credential + $current;

            return $resource;
        }, $this->resources);

        return new self($this->name, $this->branch, $resources, [], [], [], $this->formatVersion, $this->fileHash);
    }
}
