<?php

namespace App\Services;

final readonly class DockerBuildCacheConfiguration
{
    public const BUILDER_NAME = 'coolify-docker-cache';

    public static function localCachePath(string $applicationUuid): string
    {
        return base_configuration_dir().'/docker-build-cache/'.$applicationUuid;
    }

    /**
     * @param  array{type: 'registry'|'raw', value: string}  $cacheFrom
     * @param  array{type: 'registry'|'raw', value: string}  $cacheTo
     */
    private function __construct(
        private array $cacheFrom,
        private array $cacheTo,
        private string $failurePolicy,
        private string $deploymentContext,
        private string $configurationSource,
    ) {}

    /**
     * @param  array<string, mixed>|null  $production
     * @param  array<string, mixed>|null  $preview
     */
    public static function resolve(?array $production, ?array $preview, bool $isPreview): ?self
    {
        $configuration = $production;
        $configurationSource = 'production';

        if ($isPreview && $preview !== null) {
            $configuration = $preview;
            $configurationSource = 'preview';
        }

        if ($configuration === null || ($configuration['enabled'] ?? false) !== true) {
            return null;
        }

        return self::fromArray(
            configuration: $configuration,
            deploymentContext: $isPreview ? 'preview' : 'production',
            configurationSource: $configurationSource,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration, string $deploymentContext, string $configurationSource): self
    {
        /** @var array{type: 'registry'|'raw', value: string} $cacheFrom */
        $cacheFrom = $configuration['cache_from'];
        /** @var array{type: 'registry'|'raw', value: string} $cacheTo */
        $cacheTo = $configuration['cache_to'];

        return new self(
            cacheFrom: $cacheFrom,
            cacheTo: $cacheTo,
            failurePolicy: $configuration['failure_policy'] ?? 'continue',
            deploymentContext: $deploymentContext,
            configurationSource: $configurationSource,
        );
    }

    /** @return list<string> */
    public function buildArguments(bool $forceRebuild): array
    {
        $arguments = [];

        if (! $forceRebuild) {
            $arguments[] = '--cache-from '.$this->buildCacheValue($this->cacheFrom, export: false);
        }

        $arguments[] = '--cache-to '.$this->buildCacheValue($this->cacheTo, export: true);

        return $arguments;
    }

    public function usesLocalCache(): bool
    {
        return str_starts_with($this->buildCacheValue($this->cacheFrom, export: false), 'type=local,')
            || str_starts_with($this->buildCacheValue($this->cacheTo, export: true), 'type=local,');
    }

    public function shouldFail(): bool
    {
        return $this->failurePolicy === 'fail';
    }

    public function registryImportReference(): ?string
    {
        if ($this->cacheFrom['type'] !== 'registry') {
            return null;
        }

        return $this->cacheFrom['value'];
    }

    public function deploymentContext(): string
    {
        return $this->deploymentContext;
    }

    public function configurationSource(): string
    {
        return $this->configurationSource;
    }

    /** @param  array{type: 'registry'|'raw', value: string}  $cache */
    private function buildCacheValue(array $cache, bool $export): string
    {
        if ($cache['type'] === 'raw') {
            return $cache['value'];
        }

        $value = 'type=registry,ref='.$cache['value'];

        if ($export) {
            $value .= ',mode=max';
        }

        return $value;
    }
}
