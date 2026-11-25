<?php

namespace App\Services\DeployTriggers\Resolution;

use App\Services\DeployTriggers\CompiledTriggerSet;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;

/**
 * Resolves which repository paths can affect an application, as an AND of limits:
 * build context, Dockerfile or Compose sources, and the JS workspace closure.
 * Anything a limit cannot be sure of drops that limit, so the set only ever widens.
 */
final class TriggerSetBuilder
{
    /** Root files no build reads. A README, a CHANGELOG, or .editorconfig can be (docs sites, linters), so they stay. */
    private const ROOT_META_FILES = ['LICENSE*', 'CONTRIBUTING*', 'CODE_OF_CONDUCT*', 'SECURITY*', '.gitlab-ci.yml', 'CODEOWNERS'];

    /** Compose flags that read files other than the Compose file Coolify knows. */
    private const COMPOSE_FILE_FLAGS = '/(?:^|\s)(?:-f|--file|--env-file|--project-directory)(?:=|\s)/';

    private const ROOT_META_DIRECTORIES = ['.github', '.gitlab', '.vscode', '.idea', '.husky', '.changeset'];

    /** @var list<array<string, mixed>> */
    private array $rulesets = [];

    /** @var list<string> */
    private array $display = [];

    /** @var list<string> */
    private array $limits = [];

    /** @var list<array{scope: string, reason: string}> */
    private array $widened = [];

    /** @var list<string> paths a Dockerfile or Compose file names explicitly */
    private array $explicitPaths = [];

    /** @var array<string, list<string>> package name => tsconfig path aliases that point into it */
    private array $aliases = [];

    private ?RepositorySnapshot $snapshot = null;

    /**
     * @param  list<string>  $referencedPackages  packages the application's files reference without declaring them
     */
    public function build(EvaluateApplicationInput $application, RepositorySnapshot $snapshot, array $referencedPackages = []): BuiltTriggerSet
    {
        $this->rulesets = $this->display = $this->limits = $this->widened = $this->explicitPaths = $this->aliases = [];
        $this->snapshot = $snapshot;
        ['context' => $context, 'dockerfile' => $dockerfile, 'compose' => $compose, 'ignoreFiles' => $ignoreFiles] = self::locations($application);
        $snapshot->prefetch(array_values(array_filter([$dockerfile, $compose, ...$ignoreFiles, ...JsWorkspaces::manifestPaths($snapshot), ...self::tsconfigPaths($snapshot), ...$snapshot->symlinks()])));

        if ($compose === null) {
            // Compose builds from any directory of the clone; its own limit bounds it.
            $this->buildContextLimit($snapshot, $context, $dockerfile, $ignoreFiles);
        }
        if ($dockerfile !== null) {
            $this->dockerfileLimit($snapshot, $context, $dockerfile, $ignoreFiles);
        }
        if ($compose !== null) {
            if (self::composeCommandsReadOtherFiles($application)) {
                $this->widen('compose', 'The Compose commands name other Compose or env files.');
            } else {
                $this->composeLimit($snapshot, $compose, $context);
            }
        }
        [$graph, $closure, $anchor, $resolvers] = $this->workspaceLimit($application, $snapshot, $context, $dockerfile, $compose, $referencedPackages);
        $this->followSymlinks($snapshot);

        return new BuiltTriggerSet(
            compiled: ['version' => 1, 'all_of' => $this->rulesets, 'lockfiles' => []],
            inputPatterns: $this->inputPatterns($application, $snapshot, $dockerfile, $compose),
            display: $this->display,
            limits: $this->limits,
            resolvers: $resolvers,
            anchor: $anchor,
            widened: $this->widened,
            graph: $graph,
            closure: $closure,
            aliases: $this->aliases,
        );
    }

    /**
     * The files of an application that resolution always reads.
     *
     * @return list<string>
     */
    public static function wantedPaths(EvaluateApplicationInput $application): array
    {
        $locations = self::locations($application);

        return array_values(array_filter([$locations['dockerfile'], $locations['compose'], ...$locations['ignoreFiles']]));
    }

    /**
     * @return array{context: string, dockerfile: ?string, compose: ?string, ignoreFiles: list<string>}
     */
    private static function locations(EvaluateApplicationInput $application): array
    {
        $context = RepoPath::join('', $application->baseDirectory) ?? '';
        // Both locations are relative to the base directory, with a leading slash.
        $dockerfile = $application->buildPack === 'dockerfile' ? RepoPath::join($context, ltrim($application->dockerfileLocation ?: '/Dockerfile', '/')) : null;
        $compose = $application->buildPack === 'dockercompose' ? RepoPath::join($context, ltrim($application->dockerComposeLocation ?: '/docker-compose.yaml', '/')) : null;
        $ignoreFiles = $dockerfile === null ? [] : [$dockerfile.'.dockerignore', ($context === '' ? '' : $context.'/').'.dockerignore'];

        return ['context' => $context, 'dockerfile' => $dockerfile, 'compose' => $compose, 'ignoreFiles' => $ignoreFiles];
    }

    /**
     * @param  list<string>  $ignoreFiles
     */
    private function buildContextLimit(RepositorySnapshot $snapshot, string $context, ?string $dockerfile, array $ignoreFiles): void
    {
        $rules = $context === '' ? [] : [self::rule('include', 'dir', $context)];
        if ($dockerfile !== null) {
            // BuildKit prefers <Dockerfile>.dockerignore over the one in the context.
            $ignoreFile = collect($ignoreFiles)->first(fn (string $path): bool => $snapshot->has($path));
            if ($ignoreFile !== null) {
                array_push($rules, ...DockerignoreRules::parse((string) $snapshot->read($ignoreFile), $context));
            }
            $rules[] = self::rule('include', 'file', $dockerfile);
            foreach ($ignoreFiles as $path) {
                $rules[] = self::rule('include', 'file', $path);
            }
        }

        if ($context === '' && $rules === []) {
            return;
        }
        $this->addRuleset('build_context', $context === '' ? 'include' : 'exclude', $rules);
    }

    /**
     * @param  list<string>  $ignoreFiles
     */
    private function dockerfileLimit(RepositorySnapshot $snapshot, string $context, string $dockerfile, array $ignoreFiles): void
    {
        $content = $snapshot->read($dockerfile);
        $sources = $content === null ? null : DockerfileSources::parse($content);
        if ($sources === null) {
            $this->widen('dockerfile', $content === null ? "{$dockerfile} was not found." : 'The Dockerfile copies the whole build context or a dynamic path.');

            return;
        }

        $rules = [self::rule('include', 'file', $dockerfile)];
        foreach ($ignoreFiles as $path) {
            $rules[] = self::rule('include', 'file', $path);
        }
        $paths = [];
        foreach ($sources as $source) {
            $path = RepoPath::join($context, $source);
            if ($path === null || str_contains($source, '[')) {
                $this->widen('dockerfile', "The Dockerfile copies {$source}, which Coolify cannot bound.");

                return;
            }
            $paths[] = $path;
            array_push($rules, ...self::pathRules('include', $path));
        }
        array_push($this->explicitPaths, ...$paths);
        $this->addRuleset('dockerfile', 'exclude', $rules);
    }

    private function composeLimit(RepositorySnapshot $snapshot, string $compose, string $context): void
    {
        $content = $snapshot->read($compose);
        $sources = $content === null ? null : ComposeSources::parse($content, $compose, $context);
        if ($sources === null) {
            $this->widen('compose', $content === null ? "{$compose} was not found." : 'The Compose file uses includes, extends, or remote build contexts.');

            return;
        }

        $rules = [];
        array_push($this->explicitPaths, ...$sources['dirs'], ...$sources['files']);
        foreach ($sources['dirs'] as $directory) {
            $rules[] = self::rule('include', 'dir', $directory);
        }
        foreach ($sources['files'] as $file) {
            $rules[] = self::rule('include', 'file', $file);
        }
        $this->addRuleset('compose', 'exclude', $rules);
    }

    /**
     * @param  list<string>  $referencedPackages
     * @return array{0: ?JsWorkspaceGraph, 1: list<string>, 2: array{resolver: string, package: string, dir: string}|null, 3: list<string>}
     */
    private function workspaceLimit(EvaluateApplicationInput $application, RepositorySnapshot $snapshot, string $context, ?string $dockerfile, ?string $compose, array $referencedPackages): array
    {
        try {
            $graph = JsWorkspaces::resolve($snapshot);
        } catch (WidenException $exception) {
            $this->widen('workspace', $exception->getMessage());

            return [null, [], null, []];
        }
        if ($graph === null) {
            return [null, [], null, []];
        }

        $resolver = match (true) {
            $snapshot->has('pnpm-workspace.yaml') => 'pnpm',
            $snapshot->has('bun.lock') || $snapshot->has('bun.lockb') => 'bun',
            $snapshot->has('yarn.lock') => 'yarn',
            default => 'npm',
        };
        $anchors = $this->anchors($application, $graph, $snapshot, $context, $dockerfile, $compose);
        if ($anchors === null) {
            $this->widen('workspace', 'The build can build packages that Coolify cannot name, such as every package, the dependents of one, or the packages of a shared Compose build context.');

            return [$graph, [], null, [$resolver]];
        }
        if ($anchors === []) {
            $this->widen('workspace', "Couldn't tell which package this application builds.");

            return [$graph, [], null, [$resolver]];
        }

        $closure = $graph->closure([...$anchors, ...$referencedPackages]);
        $closureDirectories = array_map(fn (string $name): string => $graph->packages[$name], $closure);
        $rules = [];
        foreach (self::ROOT_META_FILES as $pattern) {
            $rules[] = self::rule('exclude', 'glob', $pattern);
        }
        foreach (self::ROOT_META_DIRECTORIES as $directory) {
            $rules[] = self::rule('exclude', 'dir', $directory);
        }
        foreach (array_diff_key($graph->packages, array_flip($closure)) as $directory) {
            $rules[] = self::rule('exclude', 'dir', $directory);
        }
        foreach ($closureDirectories as $directory) {
            $rules[] = self::rule('include', 'dir', $directory);
        }
        foreach ($graph->globalPatterns as $pattern) {
            array_push($rules, ...self::pathRules('include', $pattern));
        }
        foreach ($graph->packages as $directory) {
            $rules[] = self::rule('include', 'file', $directory.'/package.json');
        }
        foreach ($graph->lifecycleDirectories as $directory) {
            $rules[] = self::rule('include', 'dir', $directory);
        }
        foreach ($application->managedConfigPaths as $path) {
            $rules[] = self::rule('include', 'file', $path);
        }
        foreach ($this->explicitPaths as $path) {
            array_push($rules, ...self::pathRules('include', $path));
        }
        $this->addRuleset('workspace', 'include', $rules);
        $this->aliases = $this->tsconfigAliases($snapshot, $graph);
        $anchor = $anchors[0];

        return [$graph, $closure, ['resolver' => $resolver, 'package' => $anchor, 'dir' => $graph->packages[$anchor]], [$resolver]];
    }

    /**
     * The packages the application builds; null when it may build packages that cannot be named.
     *
     * @return list<string>|null
     */
    private function anchors(EvaluateApplicationInput $application, JsWorkspaceGraph $graph, RepositorySnapshot $snapshot, string $context, ?string $dockerfile, ?string $compose): ?array
    {
        $anchors = [];
        if ($context !== '') {
            $anchors[] = $graph->packageOwning($context.'/x');
        }
        // Commands the build pack runs; the others may be left over from another build pack.
        $commands = match ($application->buildPack) {
            'dockerfile' => [],
            'dockercompose' => [$application->commands['compose_build'] ?? null, $application->commands['compose_start'] ?? null],
            default => [$application->commands['install'] ?? null, $application->commands['build'] ?? null, $application->commands['start'] ?? null],
        };
        if ($dockerfile !== null) {
            $anchors[] = $graph->packageOwning($dockerfile);
            $commands[] = $snapshot->read($dockerfile);
        }
        if ($compose !== null) {
            if (self::composeCommandsReadOtherFiles($application)) {
                return null;
            }
            $sources = $snapshot->has($compose) ? ComposeSources::parse((string) $snapshot->read($compose), $compose, $context) : null;
            foreach ($sources['dirs'] ?? [] as $directory) {
                $owner = $graph->packageOwning($directory.'/x');
                // A context that holds several packages (such as the root) can build any of them.
                if ($owner === null && collect($graph->packages)->contains(fn (string $package): bool => RepoPath::isWithin($package, $directory))) {
                    return null;
                }
                $anchors[] = $owner;
            }
        }
        $commands = array_values(array_filter($commands, fn (mixed $command): bool => is_string($command) && $command !== ''));
        foreach ($commands as $command) {
            $selected = $graph->packagesFromCommand($command);
            if ($selected === null) {
                return null;
            }
            array_push($anchors, ...$selected);
        }
        $anchors = array_values(array_unique(array_filter($anchors)));

        // A package the Dockerfile or a command runs by its path, such as `node packages/codegen/run.js`, counts too.
        // Only next to a named package: on its own it does not show what the application builds.
        return $anchors === [] ? [] : array_values(array_unique([...$anchors, ...$graph->packagesMentionedIn(implode("\n", $commands))]));
    }

    private static function composeCommandsReadOtherFiles(EvaluateApplicationInput $application): bool
    {
        foreach (['compose_build', 'compose_start'] as $key) {
            if (is_string($application->commands[$key] ?? null) && preg_match(self::COMPOSE_FILE_FLAGS, $application->commands[$key]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Symlinks inside the set bring their targets in: Docker and package managers follow them.
     */
    private function followSymlinks(RepositorySnapshot $snapshot): void
    {
        if ($this->rulesets === []) {
            return;
        }
        $compiled = CompiledTriggerSet::fromArray(['version' => 1, 'all_of' => $this->rulesets, 'lockfiles' => []]);
        foreach ($snapshot->symlinks() as $link) {
            $target = RepoPath::join(RepoPath::directory($link), (string) $snapshot->read($link));
            // A target outside the repository cannot change with a push; the link itself is an input pattern.
            if ($target === null || ! $compiled->matches($link)) {
                continue;
            }
            foreach (array_keys($this->rulesets) as $index) {
                $this->rulesets[$index]['rules'][] = self::rule('include', 'dir', $target);
            }
            $this->display[] = "symlink: {$target}/**";
        }
    }

    /**
     * @return list<string>
     */
    private static function tsconfigPaths(RepositorySnapshot $snapshot): array
    {
        return array_values(array_filter($snapshot->paths(), fn (string $path): bool => preg_match('#(^|/)(tsconfig|jsconfig)[^/]*\.json$#', $path) === 1));
    }

    /**
     * Path aliases (`compilerOptions.paths`) by the package they point into, so the reference check finds imports through them.
     *
     * @return array<string, list<string>>
     */
    private function tsconfigAliases(RepositorySnapshot $snapshot, JsWorkspaceGraph $graph): array
    {
        $aliases = [];
        foreach (self::tsconfigPaths($snapshot) as $path) {
            $config = Jsonc::decode((string) $snapshot->read($path));
            if ($config === null) {
                $this->widen('workspace', "{$path} could not be read.");
                $this->rulesets = array_values(array_filter($this->rulesets, fn (array $ruleset): bool => $ruleset['limit'] !== 'workspace'));
                $this->limits = array_values(array_diff($this->limits, ['workspace']));

                return [];
            }
            $options = is_array($config['compilerOptions'] ?? null) ? $config['compilerOptions'] : [];
            $base = RepoPath::join(RepoPath::directory($path), is_string($options['baseUrl'] ?? null) ? $options['baseUrl'] : '.') ?? '';
            foreach (is_array($options['paths'] ?? null) ? $options['paths'] : [] as $alias => $targets) {
                $needle = explode('*', (string) $alias, 2)[0];
                if ($needle === '' || ! is_array($targets)) {
                    continue;
                }
                foreach ($targets as $target) {
                    $directory = is_string($target) ? RepoPath::join($base, explode('*', $target, 2)[0]) : null;
                    $owner = $directory === null ? null : $graph->packageOwning(rtrim($directory, '/').'/x');
                    if ($owner !== null) {
                        $aliases[$owner][] = $needle;
                    }
                }
            }
        }

        return array_map(fn (array $needles): array => array_values(array_unique($needles)), $aliases);
    }

    /**
     * @return array{version: int, all_of: list<array<string, mixed>>, lockfiles: list<string>}
     */
    private function inputPatterns(EvaluateApplicationInput $application, RepositorySnapshot $snapshot, ?string $dockerfile, ?string $compose): array
    {
        $rules = [
            self::rule('include', 'glob', '**/package.json'),
            self::rule('include', 'file', 'pnpm-workspace.yaml'),
            self::rule('include', 'file', 'turbo.json'),
            self::rule('include', 'glob', '**/.dockerignore'),
            self::rule('include', 'glob', '**/*.dockerignore'),
            self::rule('include', 'glob', '**/tsconfig*.json'),
            self::rule('include', 'glob', '**/jsconfig*.json'),
        ];
        foreach (array_filter([$dockerfile, $compose]) as $path) {
            $rules[] = self::rule('include', 'file', $path);
        }
        foreach ([...$application->managedConfigPaths, ...$snapshot->symlinks()] as $path) {
            $rules[] = self::rule('include', 'file', $path);
        }

        return ['version' => 1, 'all_of' => [['limit' => 'inputs', 'default' => 'exclude', 'rules' => $rules]], 'lockfiles' => []];
    }

    /**
     * @param  list<array<string, string>>  $rules
     */
    private function addRuleset(string $limit, string $default, array $rules): void
    {
        $this->rulesets[] = ['limit' => $limit, 'default' => $default, 'rules' => $rules];
        $this->limits[] = $limit;
        foreach ($rules as $rule) {
            if ($limit === 'workspace' && $rule['action'] === 'exclude') {
                continue;
            }
            $path = $this->displayPath($rule);
            if ($path !== null) {
                $this->display[] = "{$limit}: ".($rule['action'] === 'exclude' ? '!' : '').$path;
            }
        }
        $this->display = array_values(array_unique($this->display));
    }

    /**
     * How a rule reads in the UI: a file as itself, a directory with `/**`. An include rule for a path that does not
     * exist yet, such as a lockfile of another package manager, still matches it but is left out of the list.
     *
     * @param  array<string, string>  $rule
     */
    private function displayPath(array $rule): ?string
    {
        if ($rule['kind'] === 'glob') {
            return $rule['pattern'];
        }
        $path = $rule['path'];
        if ($path === '') {
            return '**';
        }
        if ($this->snapshot?->has($path)) {
            return $path;
        }
        if ($this->snapshot?->directoryExists($path)) {
            return $path.'/**';
        }
        if ($rule['action'] === 'include' && $this->snapshot !== null) {
            return null;
        }

        return $rule['kind'] === 'dir' ? $path.'/**' : $path;
    }

    private function widen(string $scope, string $reason): void
    {
        $this->widened[] = ['scope' => $scope, 'reason' => $reason];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function pathRules(string $action, string $path): array
    {
        return Glob::hasWildcard($path)
            ? [self::rule($action, 'glob', $path), self::rule($action, 'glob', $path.'/**')]
            : [self::rule($action, 'dir', $path)];
    }

    /**
     * @return array<string, string>
     */
    private static function rule(string $action, string $kind, string $value): array
    {
        return ['action' => $action, 'kind' => $kind, ($kind === 'glob' ? 'pattern' : 'path') => $value];
    }
}
