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
}
