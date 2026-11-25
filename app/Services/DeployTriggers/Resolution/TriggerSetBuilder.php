<?php

namespace App\Services\DeployTriggers\Resolution;

use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;

/**
 * Resolves which repository paths can affect an application, as an AND of limits:
 * build context, Dockerfile or Compose sources, and the JS workspace closure.
 * Anything a limit cannot be sure of drops that limit, so the set only ever widens.
 */
final class TriggerSetBuilder
{
    private const ROOT_META_FILES = ['README*', 'LICENSE*', 'CHANGELOG*', 'CONTRIBUTING*', 'CODE_OF_CONDUCT*', 'SECURITY*', '.gitlab-ci.yml', '.editorconfig', 'CODEOWNERS'];

    private const ROOT_META_DIRECTORIES = ['.github', '.gitlab', '.vscode', '.idea', '.husky', '.changeset'];

    /** @var list<array<string, mixed>> */
    private array $rulesets = [];

    /** @var list<string> */
    private array $display = [];

    /** @var list<string> */
    private array $limits = [];

    /** @var list<array{scope: string, reason: string}> */
    private array $widened = [];

    /**
     * @param  list<string>  $referencedPackages  packages the application's files reference without declaring them
     */
    public function build(EvaluateApplicationInput $application, RepositorySnapshot $snapshot, array $referencedPackages = []): BuiltTriggerSet
    {
        $this->rulesets = $this->display = $this->limits = $this->widened = [];
        ['context' => $context, 'dockerfile' => $dockerfile, 'compose' => $compose, 'ignoreFiles' => $ignoreFiles] = self::locations($application);
        $snapshot->prefetch(array_values(array_filter([$dockerfile, $compose, ...$ignoreFiles, ...JsWorkspaces::manifestPaths($snapshot), ...$snapshot->symlinks()])));

        $this->buildContextLimit($snapshot, $context, $dockerfile, $ignoreFiles);
        if ($dockerfile !== null) {
            $this->dockerfileLimit($snapshot, $context, $dockerfile, $ignoreFiles);
        }
        if ($compose !== null) {
            $this->composeLimit($snapshot, $compose);
        }
        [$graph, $closure, $anchor, $resolvers] = $this->workspaceLimit($application, $snapshot, $context, $dockerfile, $compose, $referencedPackages);

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
        $dockerfile = $application->buildPack === 'dockerfile' ? RepoPath::join($context, $application->dockerfileLocation ?: '/Dockerfile') : null;
        $compose = $application->buildPack === 'dockercompose' ? RepoPath::join($context, $application->dockerComposeLocation ?: '/docker-compose.yaml') : null;
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
        foreach ($sources as $source) {
            $path = RepoPath::join($context, $source);
            if ($path === null) {
                $this->widen('dockerfile', "{$source} leaves the repository.");

                return;
            }
            array_push($rules, ...self::pathRules('include', $path));
        }
        $this->addRuleset('dockerfile', 'exclude', $rules);
    }

    private function composeLimit(RepositorySnapshot $snapshot, string $compose): void
    {
        $content = $snapshot->read($compose);
        $sources = $content === null ? null : ComposeSources::parse($content, $compose);
        if ($sources === null) {
            $this->widen('compose', $content === null ? "{$compose} was not found." : 'The Compose file uses includes, extends, or remote build contexts.');

            return;
        }

        $rules = [];
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

        foreach ($snapshot->symlinks() as $link) {
            if (! collect($closureDirectories)->contains(fn (string $directory): bool => RepoPath::isWithin($link, $directory))) {
                continue;
            }
            $target = RepoPath::join(RepoPath::directory($link), (string) $snapshot->read($link));
            if ($target === null) {
                $this->widen('workspace', "The symlink {$link} points outside the repository.");

                return [$graph, $closure, null, [$resolver]];
            }
            $rules[] = self::rule('include', 'dir', $target);
        }

        $this->addRuleset('workspace', 'include', $rules);
        $anchor = $anchors[0];

        return [$graph, $closure, ['resolver' => $resolver, 'package' => $anchor, 'dir' => $graph->packages[$anchor]], [$resolver]];
    }

    /**
     * @return list<string>
     */
    private function anchors(EvaluateApplicationInput $application, JsWorkspaceGraph $graph, RepositorySnapshot $snapshot, string $context, ?string $dockerfile, ?string $compose): array
    {
        $anchors = [];
        if ($context !== '') {
            $anchors[] = $graph->packageOwning($context.'/x');
        }
        if ($dockerfile !== null) {
            $anchors[] = $graph->packageOwning($dockerfile);
        }
        if ($compose !== null) {
            $sources = $snapshot->has($compose) ? ComposeSources::parse((string) $snapshot->read($compose), $compose) : null;
            foreach ($sources['dirs'] ?? [] as $directory) {
                $anchors[] = $graph->packageOwning($directory.'/x');
            }
        }
        foreach ($application->commands as $command) {
            if (is_string($command) && $command !== '') {
                $anchors[] = $graph->packageFromCommand($command);
            }
        }

        return array_values(array_unique(array_filter($anchors)));
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
            $path = ($rule['path'] ?? $rule['pattern']).($rule['kind'] === 'dir' ? '/**' : '');
            $this->display[] = "{$limit}: ".($rule['action'] === 'exclude' ? '!' : '').$path;
        }
        $this->display = array_values(array_unique($this->display));
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
