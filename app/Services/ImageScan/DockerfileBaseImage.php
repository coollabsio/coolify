<?php

namespace App\Services\ImageScan;

use InvalidArgumentException;

/**
 * Reads the base image of the stage a Dockerfile build produces (spec 7.4).
 */
final class DockerfileBaseImage
{
    private const MAX_STAGES = 100;

    /**
     * The image in the `FROM` of the final stage (or of the build target), with stage aliases resolved through
     * their own `FROM`. Null for `scratch`, a build argument in the reference, or a reference that is not valid.
     */
    public static function finalStage(string $dockerfile, ?string $target = null): ?string
    {
        $stages = self::stages($dockerfile);
        if ($stages === []) {
            return null;
        }

        $index = array_key_last($stages);
        if (filled($target)) {
            $index = array_search(strtolower(trim($target)), array_column($stages, 'name'), true);
            if (! is_int($index)) {
                return null;
            }
        }

        return self::resolve($stages, $index);
    }

    /**
     * @param  list<array{name: ?string, image: string}>  $stages
     */
    private static function resolve(array $stages, int $index): ?string
    {
        for ($depth = 0; $depth <= self::MAX_STAGES; $depth++) {
            $image = $stages[$index]['image'];
            if (str_contains($image, '$')) {
                return null;
            }

            $alias = null;
            for ($earlier = $index - 1; $earlier >= 0; $earlier--) {
                if ($stages[$earlier]['name'] === strtolower($image)) {
                    $alias = $earlier;
                    break;
                }
            }
            if ($alias === null) {
                break;
            }
            $index = $alias;
        }

        $image = $stages[$index]['image'];
        if (strtolower($image) === 'scratch') {
            return null;
        }

        try {
            ImageRef::parse($image);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $image;
    }

    /**
     * The `FROM` instructions in order. Skips comments and heredoc bodies, joins continuation lines, and drops flags
     * such as `--platform=…`.
     *
     * @return list<array{name: ?string, image: string}>
     */
    private static function stages(string $dockerfile): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $dockerfile) ?: [];
        $escape = self::escapeCharacter($lines);
        $stages = [];
        $instruction = '';
        $heredocs = [];

        foreach ($lines as $line) {
            if ($heredocs !== []) {
                if (trim($line) === $heredocs[0]) {
                    array_shift($heredocs);
                }

                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_ends_with($trimmed, $escape)) {
                $instruction .= substr($trimmed, 0, -1).' ';

                continue;
            }

            $instruction .= $trimmed;
            $heredocs = self::instruction($instruction, $stages);
            $instruction = '';
        }

        if ($instruction !== '') {
            self::instruction($instruction, $stages);
        }

        return array_slice($stages, 0, self::MAX_STAGES);
    }

    /**
     * Adds a `FROM` to $stages. Returns the heredoc delimiters of any other instruction, whose bodies follow it.
     *
     * @param  list<array{name: ?string, image: string}>  $stages
     * @return list<string>
     */
    private static function instruction(string $instruction, array &$stages): array
    {
        $parts = preg_split('/\s+/', trim($instruction), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return [];
        }

        if (strtoupper($parts[0]) !== 'FROM') {
            preg_match_all('/<<-?(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1/', $instruction, $matches);

            return $matches[2];
        }

        $arguments = array_values(array_filter(array_slice($parts, 1), fn (string $part): bool => ! str_starts_with($part, '--')));
        if ($arguments === []) {
            return [];
        }

        $stages[] = [
            'name' => count($arguments) >= 3 && strtolower($arguments[1]) === 'as' ? strtolower($arguments[2]) : null,
            'image' => $arguments[0],
        ];

        return [];
    }

    /**
     * The `# escape=` parser directive, which must come before any other line.
     *
     * @param  list<string>  $lines
     */
    private static function escapeCharacter(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/\A#\s*([A-Za-z]+)\s*=\s*(\S+)\s*\z/', trim($line), $directive) !== 1) {
                break;
            }
            if (strtolower($directive[1]) === 'escape' && in_array($directive[2], ['\\', '`'], true)) {
                return $directive[2];
            }
        }

        return '\\';
    }
}
