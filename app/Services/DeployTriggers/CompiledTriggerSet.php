<?php

namespace App\Services\DeployTriggers;

use InvalidArgumentException;

/**
 * Matches repository paths against a compiled trigger set.
 *
 * A path matches when every ruleset includes it. In a ruleset the last matching rule wins,
 * and the ruleset default applies when no rule matches.
 */
final class CompiledTriggerSet
{
    /**
     * @param  list<array{default: bool, dirs: array<string, int>, files: array<string, int>, globs: list<array{index: int, include: bool, regex: string}>, includes: list<bool>, above: array<string, true>}>  $rulesets
     * @param  list<string>  $lockfiles
     */
    private function __construct(private readonly array $rulesets, private readonly array $lockfiles) {}

    /**
     * @param  array<string, mixed>  $compiled
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $compiled): self
    {
        if (($compiled['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported compiled trigger set version.');
        }

        $rulesets = [];
        foreach ($compiled['all_of'] ?? [] as $ruleset) {
            $rulesets[] = self::compileRuleset(is_array($ruleset) ? $ruleset : []);
        }

        $lockfiles = array_values(array_filter($compiled['lockfiles'] ?? [], 'is_string'));

        return new self($rulesets, $lockfiles);
    }

    public function matches(string $path): bool
    {
        foreach ($this->rulesets as $ruleset) {
            if (! $this->rulesetIncludes($ruleset, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a changed path can hold files of the set below it: a submodule, or a file that replaced a directory.
     */
    public function matchesBelow(string $path): bool
    {
        if ($path === '' || $this->rulesets === []) {
            return false;
        }

        foreach ($this->rulesets as $ruleset) {
            if (! $this->rulesetIncludes($ruleset, $path) && ! $this->rulesetIncludesBelow($ruleset, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  iterable<string>  $paths
     */
    public function firstMatch(iterable $paths): ?string
    {
        foreach ($paths as $path) {
            if ($this->matches($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function lockfiles(): array
    {
        return $this->lockfiles;
    }

    public function isLockfile(string $path): bool
    {
        return in_array($path, $this->lockfiles, true);
    }

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array{default: bool, dirs: array<string, int>, files: array<string, int>, globs: list<array{index: int, include: bool, regex: string}>, includes: list<bool>, above: array<string, true>}
     */
    private static function compileRuleset(array $ruleset): array
    {
        $default = match ($ruleset['default'] ?? null) {
            'include' => true,
            'exclude' => false,
            default => throw new InvalidArgumentException('Unknown ruleset default.'),
        };

        $dirs = [];
        $files = [];
        $globs = [];
        $includes = [];
        foreach (array_values($ruleset['rules'] ?? []) as $index => $rule) {
            $include = match ($rule['action'] ?? null) {
                'include' => true,
                'exclude' => false,
                default => throw new InvalidArgumentException('Unknown rule action.'),
            };
            $includes[$index] = $include;
            match ($rule['kind'] ?? null) {
                'dir' => $dirs[self::stringField($rule, 'path')] = $index,
                'file' => $files[self::stringField($rule, 'path')] = $index,
                'glob' => $globs[] = ['index' => $index, 'include' => $include, 'regex' => self::globToRegex(self::stringField($rule, 'pattern'))],
                default => throw new InvalidArgumentException('Unknown rule kind.'),
            };
        }

        return ['default' => $default, 'dirs' => $dirs, 'files' => $files, 'globs' => $globs, 'includes' => $includes,
            'above' => self::directoriesAboveIncludes(array_values($ruleset['rules'] ?? []))];
    }

    /**
     * @param  array{default: bool, dirs: array<string, int>, files: array<string, int>, globs: list<array{index: int, include: bool, regex: string}>, includes: list<bool>, above: array<string, true>}  $ruleset
     */
    private function rulesetIncludes(array $ruleset, string $path): bool
    {
        $winner = -1;

        if (isset($ruleset['files'][$path])) {
            $winner = $ruleset['files'][$path];
        }

        $prefix = $path;
        while ($prefix !== '') {
            if (isset($ruleset['dirs'][$prefix]) && $ruleset['dirs'][$prefix] > $winner) {
                $winner = $ruleset['dirs'][$prefix];
            }
            $slash = strrpos($prefix, '/');
            $prefix = $slash === false ? '' : substr($prefix, 0, $slash);
        }
        // A dir rule for '' is the repository root.
        if (isset($ruleset['dirs']['']) && $ruleset['dirs'][''] > $winner) {
            $winner = $ruleset['dirs'][''];
        }

        foreach ($ruleset['globs'] as $glob) {
            if ($glob['index'] > $winner && preg_match($glob['regex'], $path) === 1) {
                $winner = $glob['index'];
            }
        }

        return $winner === -1 ? $ruleset['default'] : $ruleset['includes'][$winner];
    }

    /**
     * @param  array{dirs: array<string, int>, files: array<string, int>, globs: list<array{index: int, include: bool, regex: string}>, includes: list<bool>, above: array<string, true>}  $ruleset
     */
    private function rulesetIncludesBelow(array $ruleset, string $path): bool
    {
        return isset($ruleset['above'][$path]);
    }

    /**
     * The directories above the paths that include rules name, so a change of one of them can be found in O(1).
     * A glob counts by its segments before the first wildcard; one that starts with a wildcard is left out.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, true>
     */
    private static function directoriesAboveIncludes(array $rules): array
    {
        $above = [];
        foreach ($rules as $rule) {
            if (($rule['action'] ?? null) !== 'include') {
                continue;
            }
            $path = $rule['kind'] === 'glob' ? self::literalPrefix($rule['pattern']).'/*' : $rule['path'];
            for ($slash = strrpos($path, '/'); $slash !== false && $slash > 0; $slash = strrpos(substr($path, 0, $slash), '/')) {
                $above[substr($path, 0, $slash)] = true;
            }
        }

        return $above;
    }

    /**
     * The segments of a glob before its first wildcard.
     */
    private static function literalPrefix(string $pattern): string
    {
        $literal = [];
        foreach (explode('/', $pattern) as $segment) {
            if (strpbrk($segment, '*?') !== false) {
                break;
            }
            $literal[] = $segment;
        }

        return implode('/', $literal);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function stringField(array $rule, string $key): string
    {
        if (! is_string($rule[$key] ?? null)) {
            throw new InvalidArgumentException("Rule {$key} must be a string.");
        }

        return $rule[$key];
    }

    private static function globToRegex(string $pattern): string
    {
        // Consecutive `**` segments mean the same as one.
        $segments = array_values(array_filter(explode('/', $pattern), fn (string $segment, int $index): bool => $segment !== '**' || ($index === 0 || explode('/', $pattern)[$index - 1] !== '**'), ARRAY_FILTER_USE_BOTH));
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
}
