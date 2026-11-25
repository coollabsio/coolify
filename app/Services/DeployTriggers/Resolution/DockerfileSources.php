<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * The build-context paths a Dockerfile reads: COPY and ADD sources and bind mounts.
 * Null means the Dockerfile may read anything in the context, so it cannot limit the set.
 */
final class DockerfileSources
{
    private const IGNORED_FLAGS = ['chown', 'chmod', 'link', 'checksum', 'keep-git-dir'];

    /**
     * @return list<string>|null context-relative paths or globs
     */
    public static function parse(string $content): ?array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        if (! self::directivesAreStandard($lines)) {
            return null;
        }

        $stages = [];
        $sources = [];
        foreach (self::instructions($lines) as $instruction) {
            [$keyword, $arguments] = array_pad(preg_split('/\s+/', $instruction, 2) ?: [], 2, '');
            $keyword = strtoupper($keyword);

            if ($keyword === 'ONBUILD') {
                return null;
            }
            if ($keyword === 'FROM' && preg_match('/\sAS\s+(\S+)\s*$/i', $arguments, $match) === 1) {
                $stages[strtolower($match[1])] = true;
            }
            if ($keyword === 'RUN') {
                $mounts = self::bindMountSources($arguments);
                if ($mounts === null) {
                    return null;
                }
                array_push($sources, ...$mounts);
            }
            if ($keyword === 'COPY' || $keyword === 'ADD') {
                $copied = self::copySources($keyword, $arguments, $stages);
                if ($copied === null) {
                    return null;
                }
                array_push($sources, ...$copied);
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param  list<string>  $lines
     */
    private static function directivesAreStandard(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^\s*#\s*([a-z]+)\s*=\s*(\S+)\s*$/i', $line, $match) !== 1) {
                break;
            }
            $name = strtolower($match[1]);
            if ($name === 'escape' && $match[2] !== '\\') {
                return false;
            }
            if ($name === 'syntax' && preg_match('#^(docker\.io/)?docker/dockerfile(:|@|$)#', $match[2]) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Instructions with line continuations joined and comments removed.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function instructions(array $lines): array
    {
        $instructions = [];
        $current = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_ends_with($trimmed, '\\')) {
                $current .= substr($trimmed, 0, -1).' ';

                continue;
            }
            $instructions[] = trim($current.$trimmed);
            $current = '';
        }
        if (trim($current) !== '') {
            $instructions[] = trim($current);
        }

        return $instructions;
    }

    /**
     * @param  array<string, true>  $stages
     * @return list<string>|null
     */
    private static function copySources(string $keyword, string $arguments, array $stages): ?array
    {
        if (str_contains($arguments, '<<')) {
            return null;
        }

        $tokens = self::tokens($arguments);
        $paths = [];
        foreach ($tokens as $token) {
            if (! str_starts_with($token, '--')) {
                $paths[] = $token;

                continue;
            }
            [$flag, $value] = array_pad(explode('=', substr($token, 2), 2), 2, '');
            $flag = strtolower($flag);
            if ($flag === 'from') {
                // A stage or an image reads nothing from the context; any other name is a named build context.
                $isImage = str_contains($value, ':') || str_contains($value, '/') || str_contains($value, '@');

                return isset($stages[strtolower($value)]) || $isImage ? [] : null;
            }
            if (! in_array($flag, self::IGNORED_FLAGS, true)) {
                return null;
            }
        }

        array_pop($paths);
        $sources = [];
        foreach ($paths as $path) {
            if ($keyword === 'ADD' && (str_contains($path, '://') || str_starts_with($path, 'git@'))) {
                continue;
            }
            $source = self::contextPath($path);
            if ($source === null) {
                return null;
            }
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * @return list<string>|null
     */
    private static function bindMountSources(string $arguments): ?array
    {
        $sources = [];
        foreach (self::tokens($arguments) as $token) {
            if (! str_starts_with($token, '--mount=')) {
                continue;
            }
            $options = [];
            foreach (explode(',', substr($token, 8)) as $option) {
                [$key, $value] = array_pad(explode('=', $option, 2), 2, '');
                $options[strtolower($key)] = $value;
            }
            if (($options['type'] ?? 'bind') !== 'bind' || isset($options['from'])) {
                continue;
            }
            $source = $options['source'] ?? $options['src'] ?? null;
            if ($source === null) {
                return null;
            }
            $path = self::contextPath($source);
            if ($path === null) {
                return null;
            }
            $sources[] = $path;
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $arguments): array
    {
        $trimmed = trim($arguments);
        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }

        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $trimmed, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): string => $match[3] ?? ($match[2] !== '' ? $match[2] : $match[1]), $matches);
    }

    private static function contextPath(string $path): ?string
    {
        if (str_contains($path, '$')) {
            return null;
        }
        $normalized = RepoPath::join('', $path);
        if ($normalized === null || in_array($normalized, ['', '*', '**'], true)) {
            return null;
        }

        return $normalized;
    }
}
