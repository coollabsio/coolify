<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The watch paths of an application: one glob for each line, and a line that starts with `!`
 * excludes. The last pattern that matches a file wins. v4 applications and cluster applications
 * (decision 0033) use this matcher.
 */
final class WatchPaths
{
    /**
     * Trims each line and removes a leading `/`, also after `!`. Empty lines go away. Null when no
     * pattern stays.
     */
    public static function normalize(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        $paths = collect(explode("\n", $value))
            ->map(function (string $path): string {
                $path = trim($path);
                if (str_starts_with($path, '!')) {
                    return '!'.ltrim(trim(substr($path, 1)), '/');
                }

                return ltrim($path, '/');
            })
            ->filter(fn (string $path): bool => $path !== '');

        return $paths->isEmpty() ? null : trim($paths->implode("\n"));
    }

    /**
     * Whether a push or a pull request event builds. Without watch paths, or when the changed
     * files are unknown (null), it builds. Else at least one changed file must match.
     *
     * @param  Collection<int, string>|null  $changedFiles
     */
    public static function triggers(?string $watchPaths, ?Collection $changedFiles): bool
    {
        $watchPaths = self::normalize($watchPaths);
        if ($watchPaths === null || $changedFiles === null) {
            return true;
        }

        return self::matchPaths($changedFiles, collect(explode("\n", $watchPaths)))->isNotEmpty();
    }

    /**
     * The files that the patterns select, in order: the last matching pattern wins. A file that
     * no pattern matches is selected only when every pattern excludes.
     *
     * @param  Collection<int, string>  $files
     * @param  Collection<int, string>|null  $patterns
     * @return Collection<int, string>
     */
    public static function matchPaths(Collection $files, ?Collection $patterns): Collection
    {
        if ($patterns === null || $patterns->isEmpty()) {
            return collect();
        }

        return $files->filter(function (string $file) use ($patterns): bool {
            $selected = null;
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if ($pattern === '') {
                    continue;
                }
                $excludes = str_starts_with($pattern, '!');
                if (self::globMatch($excludes ? substr($pattern, 1) : $pattern, $file)) {
                    $selected = ! $excludes;
                }
            }

            return $selected ?? ! $patterns->contains(fn (string $pattern): bool => ! str_starts_with(trim($pattern), '!'));
        })->values();
    }

    /** Supports `*`, `**`, `?`, `[abc]`, and `[!abc]`. */
    public static function globMatch(string $pattern, string $path): bool
    {
        return preg_match(self::globToRegex($pattern), $path) === 1;
    }

    private static function globToRegex(string $pattern): string
    {
        $regex = '';
        $inGroup = false;
        $chars = str_split($pattern);
        $length = count($chars);
        for ($i = 0; $i < $length; $i++) {
            $char = $chars[$i];
            switch ($char) {
                case '*':
                    if ($i + 1 < $length && $chars[$i + 1] === '*') {
                        // `**` matches any number of directories, with an optional `/` after it.
                        $regex .= '.*';
                        $i++;
                        if ($i + 1 < $length && $chars[$i + 1] === '/') {
                            $i++;
                        }
                    } else {
                        $regex .= '[^/]*';
                    }
                    break;
                case '?':
                    $regex .= '[^/]';
                    break;
                case '[':
                    $inGroup = true;
                    $regex .= '[';
                    if ($i + 1 < $length && ($chars[$i + 1] === '!' || $chars[$i + 1] === '^')) {
                        $regex .= '^';
                        $i++;
                    }
                    break;
                case ']':
                    if ($inGroup) {
                        $inGroup = false;
                        $regex .= ']';
                    } else {
                        $regex .= preg_quote($char, '#');
                    }
                    break;
                case '.':
                case '(':
                case ')':
                case '+':
                case '{':
                case '}':
                case '$':
                case '^':
                case '|':
                case '\\':
                    $regex .= '\\'.$char;
                    break;
                default:
                    $regex .= $char;
                    break;
            }
        }

        return '#^'.$regex.'$#';
    }
}
