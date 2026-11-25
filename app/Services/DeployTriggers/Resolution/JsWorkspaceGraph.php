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
     * Commands that build every package of the workspace.
     */
    private const ALL_PACKAGES_PATTERN = '/\b(?:pnpm|bun)\s+(?:\S+\s+)*?(?:-r|--recursive)\b|--workspaces\b|\s-ws\b|\byarn\s+workspaces\s+foreach\b|\blerna\s+(?:run|exec)\b|\bnx\s+(?:run-many|affected)\b|\bturbo\s+(?:run\s+)?[\w:-]+(?![^;&|]*(?:--filter|-F\s|--scope))/';

    /**
     * The packages a command selects: an empty list when it selects none, null when it may build
     * other packages that cannot be named (every package, a glob, a path, or a package's dependents).
     *
     * @return list<string>|null
     */
    public function packagesFromCommand(string $command): ?array
    {
        $command = ' '.$command;
        $patterns = [
            '/(?:--filter|\s-F|--scope)(?:=|\s+)(["\']?)([^\s"\']+)\1/',
            '/(?:--workspace|\s-w)(?:=|\s+)(["\']?)([^\s"\']+)\1/',
            '/\byarn\s+workspace\s+()(\S+)/',
            '/\bnx\s+(?:build|run|serve)\s+()([^\s:]+)/',
            '/\bturbo\s+prune\s+()([^\s-][^\s]*)/',
        ];
        $packages = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $command, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (str_starts_with($match[2], '...')) {
                    return null;
                }
                $package = $this->packageNamed($match[2]);
                if ($package === null) {
                    return null;
                }
                $packages[] = $package;
            }
        }
        if ($packages === [] && preg_match(self::ALL_PACKAGES_PATTERN, $command) === 1) {
            return null;
        }

        return array_values(array_unique($packages));
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
