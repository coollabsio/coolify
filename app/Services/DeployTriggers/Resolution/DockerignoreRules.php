<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * Exclude rules from a .dockerignore file. A file with exceptions (`!`), character classes, or escapes yields
 * no rules, which widens the set instead of guessing Docker's matching.
 */
final class DockerignoreRules
{
    /**
     * @return list<array{action: 'exclude', kind: 'dir'|'glob', path?: string, pattern?: string}>
     */
    public static function parse(string $content, string $contextDirectory): array
    {
        $rules = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '!') || strpbrk($line, '[\\') !== false) {
                return [];
            }

            $pattern = RepoPath::join('', $line);
            if ($pattern === null) {
                return [];
            }
            if ($pattern === '') {
                continue;
            }

            $full = $contextDirectory === '' ? $pattern : $contextDirectory.'/'.$pattern;
            if (Glob::hasWildcard($pattern)) {
                // Docker also excludes everything below a matched directory.
                $rules[] = ['action' => 'exclude', 'kind' => 'glob', 'pattern' => $full];
                $rules[] = ['action' => 'exclude', 'kind' => 'glob', 'pattern' => $full.'/**'];
            } else {
                $rules[] = ['action' => 'exclude', 'kind' => 'dir', 'path' => $full];
            }
        }

        return $rules;
    }
}
