<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * A resolved trigger set of one application at one commit.
 */
final readonly class BuiltTriggerSet
{
    /**
     * @param  array{version: int, all_of: list<array<string, mixed>>, lockfiles: list<string>}  $compiled
     * @param  array{version: int, all_of: list<array<string, mixed>>, lockfiles: list<string>}  $inputPatterns
     * @param  list<string>  $display
     * @param  list<string>  $limits
     * @param  list<string>  $resolvers
     * @param  array{resolver: string, package: string, dir: string}|null  $anchor
     * @param  list<array{scope: string, reason: string}>  $widened
     * @param  list<string>  $closure
     * @param  array<string, list<string>>  $aliases  package name => tsconfig path aliases that point into it
     */
    public function __construct(
        public array $compiled,
        public array $inputPatterns,
        public array $display,
        public array $limits,
        public array $resolvers,
        public ?array $anchor,
        public array $widened,
        public ?JsWorkspaceGraph $graph,
        public array $closure,
        public array $aliases = [],
    ) {}

    /**
     * Workspace packages outside the closure (and not otherwise included), name => directory.
     *
     * @return array<string, string>
     */
    public function excludedPackages(): array
    {
        if ($this->graph === null || $this->closure === [] || ! in_array('workspace', $this->limits, true)) {
            return [];
        }

        return array_filter(
            array_diff_key($this->graph->packages, array_flip($this->closure)),
            fn (string $directory): bool => ! in_array($directory, $this->graph->lifecycleDirectories, true),
        );
    }

    /**
     * What a file of the application contains when it uses an excluded package: its name, its directory,
     * a relative path to it, and the tsconfig aliases that point into it.
     *
     * @return array<string, list<string>>
     */
    public function referenceNeedles(): array
    {
        $needles = [];
        foreach ($this->excludedPackages() as $name => $directory) {
            $needles[$name] = array_values(array_unique([$name, $directory, '../'.basename($directory), ...($this->aliases[$name] ?? [])]));
        }

        return $needles;
    }

    /**
     * The package that owns a matched file, for the "why" chain of a deployment.
     *
     * @return list<string>
     */
    public function via(string $path): array
    {
        $owner = $this->graph?->packageOwning($path);
        if ($owner === null || $this->anchor === null) {
            return [];
        }

        return $owner === $this->anchor['package'] ? [$owner] : [$this->anchor['package'], $owner];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'compiled' => $this->compiled,
            'input_patterns' => $this->inputPatterns,
            'display' => $this->display,
            'limits' => $this->limits,
            'resolvers' => $this->resolvers,
            'anchor' => $this->anchor,
            'widened' => $this->widened,
            'packages' => $this->graph === null ? [] : array_intersect_key($this->graph->packages, array_flip($this->closure)),
            'excluded' => $this->excludedPackages(),
            // So a referenced package brings its own workspace dependencies in.
            'dependencies' => array_filter(array_intersect_key($this->graph?->dependencies ?? [], $this->excludedPackages())),
            'reference_needles' => $this->referenceNeedles(),
        ];
    }
}
