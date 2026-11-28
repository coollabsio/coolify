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
     * @param  array<string, array<string, string>>  $scripts  package name (`''` for the root) => its package.json scripts
     */
    public function __construct(
        public array $packages,
        public array $dependencies,
        public array $lifecycleDirectories,
        public array $globalPatterns,
        public array $scripts = [],
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
        $packages = [];
        // Each statement on its own: one that names a package does not bound another that builds them all.
        foreach (self::statements($this->withScripts($command)) as $statement) {
            $selected = $this->statementPackages($statement);
            if ($selected === null || ($selected === [] && preg_match(self::ALL_PACKAGES_PATTERN, $statement) === 1)) {
                return null;
            }
            array_push($packages, ...$selected);
        }

        return array_values(array_unique($packages));
    }

    /**
     * The command followed by the bodies of the package scripts it runs, recursively. Without a package
     * selector the working directory is unknown, so the script of every manifest that defines it counts.
     */
    public function withScripts(string $command): string
    {
        $seen = [];

        return $this->expandScripts($command, $seen);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function expandScripts(string $command, array &$seen): string
    {
        foreach (self::statements($command) as $statement) {
            $script = self::invokedScript($statement);
            if ($script === null) {
                continue;
            }
            foreach ($this->statementPackages($statement) ?: array_keys($this->scripts) as $owner) {
                foreach (["pre{$script}", $script, "post{$script}"] as $name) {
                    $body = $this->scripts[$owner][$name] ?? null;
                    if (! is_string($body) || isset($seen["{$owner}\0{$name}"])) {
                        continue;
                    }
                    $seen["{$owner}\0{$name}"] = true;
                    $command .= "\n".$this->expandScripts($body, $seen);
                }
            }
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private static function statements(string $command): array
    {
        return array_map(
            // Exec form (`["pnpm", "-r", "start"]`) reads as the plain command.
            fn (string $statement): string => ' '.(preg_replace('/[",\[\]]/', ' ', $statement) ?? $statement),
            preg_split('/&&|\|\||[;|\n]/', preg_replace('/\\\\\r?\n/', ' ', $command) ?? $command) ?: [],
        );
    }

    private const VALUE_FLAGS = ['--filter', '-F', '--workspace', '-w', '--scope', '-C', '--dir', '--prefix', '--cwd'];

    /**
     * The package script a statement runs, such as `build` for `npm run build`, `pnpm build`, or `yarn workspace web build`.
     */
    private static function invokedScript(string $statement): ?string
    {
        $tokens = preg_split('/\s+/', trim($statement)) ?: [];
        $start = null;
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
                $start = $index + 1;

                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $words = [];
        for ($index = $start; $index < count($tokens); $index++) {
            if (str_starts_with($tokens[$index], '-')) {
                $index += in_array($tokens[$index], self::VALUE_FLAGS, true) ? 1 : 0;

                continue;
            }
            $words[] = trim($tokens[$index], "'");
        }

        return match ($words[0] ?? null) {
            null => null,
            'run', 'run-script' => $words[1] ?? null,
            'workspace' => $words[2] ?? null,
            default => $words[0],
        };
    }

    /**
     * Workspace packages whose directory (or scoped name) a text such as a Dockerfile mentions.
     *
     * @return list<string>
     */
    public function packagesMentionedIn(string $text): array
    {
        $mentioned = [];
        foreach ($this->packages as $name => $directory) {
            $needles = array_filter([$directory, str_contains($name, '/') ? $name : null]);
            foreach ($needles as $needle) {
                if (preg_match('#(?<![\w.@-])'.preg_quote($needle, '#').'(?![\w.-])#', $text) === 1) {
                    $mentioned[] = $name;

                    break;
                }
            }
        }

        return $mentioned;
    }

    /**
     * @return list<string>|null
     */
    private function statementPackages(string $command): ?array
    {
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

        return $packages;
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
