<?php

namespace App\Services\DeployTriggers\Resolution;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The repository paths a Compose file builds from or mounts. Null means it cannot be bounded.
 */
final class ComposeSources
{
    /**
     * @return array{dirs: list<string>, files: list<string>}|null
     */
    public static function parse(string $yaml, string $composePath): ?array
    {
        try {
            $document = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }
        if (! is_array($document) || isset($document['include'])) {
            return null;
        }

        $base = RepoPath::directory($composePath);
        $dirs = [];
        $files = [$composePath];
        foreach (is_array($document['services'] ?? null) ? $document['services'] : [] as $service) {
            if (! is_array($service)) {
                continue;
            }
            if (isset($service['extends'])) {
                return null;
            }
            if (! self::addBuild($service['build'] ?? null, $base, $dirs, $files)) {
                return null;
            }
            foreach (self::envFiles($service['env_file'] ?? null) as $envFile) {
                $path = RepoPath::join($base, $envFile);
                if ($path === null) {
                    return null;
                }
                $files[] = $path;
            }
            foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                $source = is_array($volume) ? (($volume['type'] ?? null) === 'bind' ? ($volume['source'] ?? null) : null) : explode(':', (string) $volume)[0];
                if (! is_string($source) || ! str_starts_with($source, '.')) {
                    continue;
                }
                $path = RepoPath::join($base, $source);
                if ($path === null) {
                    return null;
                }
                $dirs[] = $path;
            }
        }

        foreach (['configs', 'secrets'] as $section) {
            foreach (is_array($document[$section] ?? null) ? $document[$section] : [] as $entry) {
                if (is_array($entry) && is_string($entry['file'] ?? null)) {
                    $path = RepoPath::join($base, $entry['file']);
                    if ($path === null) {
                        return null;
                    }
                    $files[] = $path;
                }
            }
        }

        return ['dirs' => array_values(array_unique($dirs)), 'files' => array_values(array_unique($files))];
    }

    /**
     * @param  list<string>  $dirs
     * @param  list<string>  $files
     */
    private static function addBuild(mixed $build, string $base, array &$dirs, array &$files): bool
    {
        if ($build === null) {
            return true;
        }
        $build = is_string($build) ? ['context' => $build] : $build;
        if (! is_array($build)) {
            return false;
        }

        $context = (string) ($build['context'] ?? '.');
        if (self::isRemote($context)) {
            return false;
        }
        $contextPath = RepoPath::join($base, $context);
        if ($contextPath === null) {
            return false;
        }
        $dirs[] = $contextPath;

        if (is_string($build['dockerfile'] ?? null)) {
            $dockerfile = RepoPath::join($contextPath, $build['dockerfile']);
            if ($dockerfile === null) {
                return false;
            }
            $files[] = $dockerfile;
        }

        $additional = $build['additional_contexts'] ?? [];
        foreach (is_array($additional) ? $additional : [] as $key => $value) {
            $value = is_int($key) ? explode('=', (string) $value, 2)[1] ?? '' : (string) $value;
            if (self::isRemote($value) || str_contains($value, '://') || str_starts_with($value, 'service:')) {
                continue;
            }
            $path = RepoPath::join($base, $value);
            if ($path === null) {
                return false;
            }
            $dirs[] = $path;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function envFiles(mixed $envFile): array
    {
        $entries = is_array($envFile) ? $envFile : ($envFile === null ? [] : [$envFile]);

        return array_values(array_filter(array_map(
            fn (mixed $entry): ?string => is_string($entry) ? $entry : (is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : null),
            $entries,
        )));
    }

    private static function isRemote(string $value): bool
    {
        return str_contains($value, '://') || str_starts_with($value, 'git@') || str_ends_with($value, '.git');
    }
}
