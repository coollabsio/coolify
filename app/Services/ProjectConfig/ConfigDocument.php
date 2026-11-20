<?php

namespace App\Services\ProjectConfig;

/**
 * A parsed coolify.json file, before environment overrides are merged.
 */
final readonly class ConfigDocument
{
    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @param  array<string, array<string, mixed>>  $environments
     * @param  list<array{from: string, to: string}>  $moved
     * @param  list<string>  $unmanage
     */
    public function __construct(
        public int $version,
        public ?string $project,
        public array $resources,
        public array $environments,
        public array $moved,
        public array $unmanage,
        public string $fileHash,
    ) {}
}
