<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * Repository-relative paths: no leading slash, no `.` or `..` segments.
 */
final class RepoPath
{
    /**
     * Joins and normalizes; null when the result leaves the repository.
     */
    public static function join(string $directory, string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $parts = str_starts_with($path, '/') ? [] : explode('/', $directory);
        $segments = [];
        foreach ([...$parts, ...explode('/', $path)] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    public static function directory(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    public static function isWithin(string $path, string $directory): bool
    {
        return $directory === '' || $path === $directory || str_starts_with($path, $directory.'/');
    }
}
