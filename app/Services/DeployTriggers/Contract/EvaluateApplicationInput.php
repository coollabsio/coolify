<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class EvaluateApplicationInput
{
    /**
     * @param  'smart'|'watch_paths'  $mode
     * @param  array{install: ?string, build: ?string, start: ?string, compose_build: ?string, compose_start: ?string}  $commands
     * @param  list<string>  $referencePaths
     * @param  list<string>  $managedConfigPaths
     */
    public function __construct(
        public string $id,
        public ?string $baseCommit,
        public ?string $mergeBaseRef,
        public string $mode,
        public ?string $watchPaths,
        public string $buildPack,
        public string $baseDirectory,
        public ?string $dockerfileLocation,
        public ?string $dockerComposeLocation,
        public array $commands,
        public array $referencePaths,
        public array $managedConfigPaths,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withBase = true): array
    {
        $data = [
            'id' => $this->id,
            'base_commit' => $this->baseCommit,
            'merge_base_ref' => $this->mergeBaseRef,
            'mode' => $this->mode,
            'watch_paths' => $this->watchPaths,
            'build_pack' => $this->buildPack,
            'base_directory' => $this->baseDirectory,
            'dockerfile_location' => $this->dockerfileLocation,
            'docker_compose_location' => $this->dockerComposeLocation,
            'commands' => $this->commands,
            'reference_paths' => $this->referencePaths,
            'managed_config_paths' => $this->managedConfigPaths,
        ];

        if (! $withBase) {
            unset($data['base_commit'], $data['merge_base_ref']);
        }

        return $data;
    }
}
