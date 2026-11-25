<?php

namespace App\Services\DeployTriggers\Resolution;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads npm, yarn, pnpm, and bun workspaces (and Turborepo global dependencies) from a snapshot.
 */
final class JsWorkspaces
{
    private const LIFECYCLE_SCRIPTS = ['preinstall', 'install', 'postinstall', 'prepare'];

    private const DEPENDENCY_FIELDS = ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'];

    public const GLOBAL_FILES = [
        'package.json', 'pnpm-workspace.yaml', 'pnpm-lock.yaml', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock',
        'bun.lock', 'bun.lockb', '.npmrc', '.yarnrc', '.yarnrc.yml', '.yarn/**', '.pnpmfile.cjs', 'tsconfig*.json',
        'turbo.json', 'nx.json', '.nvmrc', '.node-version', '.tool-versions', 'mise.toml',
    ];

    /**
     * Manifest paths this resolver reads, for prefetching and input patterns.
     *
     * @return list<string>
     */
    public static function manifestPaths(RepositorySnapshot $snapshot): array
    {
        return array_values(array_filter(
            [...$snapshot->filesNamed('package.json'), 'pnpm-workspace.yaml', 'turbo.json'],
            fn (string $path): bool => $snapshot->has($path),
        ));
    }

    /**
     * @throws WidenException when a manifest cannot be read
     */
    public static function resolve(RepositorySnapshot $snapshot): ?JsWorkspaceGraph
    {
        $snapshot->prefetch(self::manifestPaths($snapshot));
        $root = $snapshot->has('package.json') ? self::json($snapshot, 'package.json') : [];
        $patterns = self::memberPatterns($snapshot, $root);
        if ($patterns === null) {
            return null;
        }

        [$includes, $excludes] = $patterns;
        $packages = [];
        $manifests = [];
        foreach ($snapshot->filesNamed('package.json') as $manifest) {
            $directory = RepoPath::directory($manifest);
            if ($directory === '' || ! self::matchesAny($includes, $directory) || self::matchesAny($excludes, $directory)) {
                continue;
            }
            $data = self::json($snapshot, $manifest);
            $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : "dir:{$directory}";
            $packages[$name] = $directory;
            $manifests[$name] = $data;
        }
        asort($packages);

        $dependencies = [];
        $lifecycle = [];
        foreach ($manifests as $name => $data) {
            $names = [];
            foreach (self::DEPENDENCY_FIELDS as $field) {
                foreach (array_keys(is_array($data[$field] ?? null) ? $data[$field] : []) as $dependency) {
                    if (isset($packages[$dependency]) && $dependency !== $name) {
                        $names[] = (string) $dependency;
                    }
                }
            }
            $dependencies[$name] = array_values(array_unique($names));
            $scripts = is_array($data['scripts'] ?? null) ? $data['scripts'] : [];
            if (array_intersect(self::LIFECYCLE_SCRIPTS, array_keys($scripts)) !== []) {
                $lifecycle[] = $packages[$name];
            }
        }
        sort($lifecycle);

        $globals = self::GLOBAL_FILES;
        if ($snapshot->has('turbo.json')) {
            $turbo = self::json($snapshot, 'turbo.json');
            foreach (is_array($turbo['globalDependencies'] ?? null) ? $turbo['globalDependencies'] : [] as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $globals[] = ltrim($pattern, '/');
                }
            }
        }

        return new JsWorkspaceGraph($packages, $dependencies, $lifecycle, array_values(array_unique($globals)));
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array{0: list<string>, 1: list<string>}|null
     */
    private static function memberPatterns(RepositorySnapshot $snapshot, array $root): ?array
    {
        $patterns = null;
        if ($snapshot->has('pnpm-workspace.yaml')) {
            try {
                $workspace = Yaml::parse((string) $snapshot->read('pnpm-workspace.yaml'));
            } catch (ParseException) {
                throw new WidenException('pnpm-workspace.yaml is not valid YAML.');
            }
            $patterns = is_array($workspace['packages'] ?? null) ? $workspace['packages'] : null;
        }
        if ($patterns === null) {
            $workspaces = $root['workspaces'] ?? null;
            $patterns = is_array($workspaces) ? (array_is_list($workspaces) ? $workspaces : ($workspaces['packages'] ?? null)) : null;
        }
        if (! is_array($patterns) || $patterns === []) {
            return null;
        }

        $includes = [];
        $excludes = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }
            $negated = str_starts_with($pattern, '!');
            $normalized = rtrim(preg_replace('#^\./#', '', ltrim($pattern, '!')) ?? '', '/');
            if ($normalized === '') {
                continue;
            }
            $negated ? $excludes[] = $normalized : $includes[] = $normalized;
        }

        return [$includes, $excludes];
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(array $patterns, string $directory): bool
    {
        foreach ($patterns as $pattern) {
            if (Glob::matches($pattern, $directory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(RepositorySnapshot $snapshot, string $path): array
    {
        $decoded = json_decode((string) $snapshot->read($path), true);
        if (! is_array($decoded)) {
            throw new WidenException("{$path} is not valid JSON.");
        }

        return $decoded;
    }
}
