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
}
