<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * The packages of an npm, yarn, pnpm, or bun workspace and the links between them.
 */
final readonly class JsWorkspaceGraph
{
    /**
     * @param  array<string, string>  $packages  name => directory, sorted by directory
     * @param  array<string, list<string>>  $dependencies  name => workspace package names it depends on
     * @param  list<string>  $lifecycleDirectories  members with install scripts, which `pnpm install` runs for every member
     * @param  list<string>  $globalPatterns  files and globs every member build reads
     */
    public function __construct(
        public array $packages,
        public array $dependencies,
        public array $lifecycleDirectories,
        public array $globalPatterns,
    ) {}

    /**
     * The packages and every workspace package they depend on, transitively, in discovery order.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function closure(array $names): array
    {
        $seen = [];
        $queue = array_values(array_filter($names, fn (string $name): bool => isset($this->packages[$name])));
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            foreach ($this->dependencies[$name] ?? [] as $dependency) {
                $queue[] = $dependency;
            }
        }

        return array_keys($seen);
    }

    public function packageOwning(string $path): ?string
    {
        $owner = null;
        $length = -1;
        foreach ($this->packages as $name => $directory) {
            if (RepoPath::isWithin($path, $directory) && strlen($directory) > $length) {
                $owner = $name;
                $length = strlen($directory);
            }
        }

        return $owner;
    }

    /**
     * The package a build or install command selects, or null when it selects none or several.
     */
    public function packageFromCommand(string $command): ?string
    {
        $patterns = [
            '/(?:--filter|\s-F)(?:=|\s+)(["\']?)([^\s"\']+)\1/',
            '/(?:--workspace|\s-w)(?:=|\s+)(["\']?)([^\s"\']+)\1/',
            '/\byarn\s+workspace\s+()(\S+)/',
            '/\bnx\s+(?:build|run|serve)\s+()([^\s:]+)/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, ' '.$command, $match) === 1) {
                return $this->packageNamed($match[2]);
            }
        }

        return null;
    }

    private function packageNamed(string $selector): ?string
    {
        $selector = preg_replace('/^\.\.\.|\^?\.\.\.$/', '', $selector) ?? $selector;
        if ($selector === '' || str_contains($selector, '*') || str_starts_with($selector, '{') || str_starts_with($selector, '[')) {
            return null;
        }
        if (isset($this->packages[$selector])) {
            return $selector;
        }

        $directory = RepoPath::join('', $selector);
        $byDirectory = array_search($directory, $this->packages, true);
        if (is_string($byDirectory)) {
            return $byDirectory;
        }

        // Nx project names drop the npm scope.
        foreach (array_keys($this->packages) as $name) {
            if (str_contains($name, '/') && substr($name, strrpos($name, '/') + 1) === $selector) {
                return $name;
            }
        }

        return null;
    }
}
