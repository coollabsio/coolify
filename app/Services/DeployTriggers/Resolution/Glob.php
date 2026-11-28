<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * Path globs: `*` and `?` stay within one segment, a whole `**` segment spans any number of segments.
 */
final class Glob
{
    public static function toRegex(string $pattern): string
    {
        $segments = [];
        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '**' && end($segments) === '**') {
                continue;
            }
            $segments[] = $segment;
        }

        $last = count($segments) - 1;
        $regex = '';
        foreach ($segments as $position => $segment) {
            if ($segment === '**') {
                $regex .= match (true) {
                    $last === 0 => '.*',
                    $position === $last => '(?:/.*)?',
                    $position === 0 => '(?:.*/)?',
                    default => '/(?:.*/)?',
                };

                continue;
            }

            if ($position > 0 && $segments[$position - 1] !== '**') {
                $regex .= '/';
            }

            $regex .= implode('', array_map(fn (string $char): string => match ($char) {
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($char, '#'),
            }, mb_str_split($segment)));
        }

        return '#^'.$regex.'$#u';
    }

    public static function matches(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex($pattern), $path) === 1;
    }

    public static function hasWildcard(string $pattern): bool
    {
        return strpbrk($pattern, '*?') !== false;
    }
}
