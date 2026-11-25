<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class EvaluateApplicationInput
{
    /**
     * @param  'smart'|'watch_paths'  $mode
     * @param  array{install: ?string, build: ?string, start: ?string, compose_build: ?string, compose_start: ?string}  $commands
     * @param  list<string>  $managedConfigPaths
     * @param  array<string, mixed>|null  $storedTriggerSet  the stored set, when it is still valid for the base
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
        public array $managedConfigPaths,
        public ?array $storedTriggerSet = null,
    ) {}
}
