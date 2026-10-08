<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;
use Throwable;

class ComposeDiff
{
    private const CONTEXT = 3;

    /** Above this line count on either side, skip the quadratic LCS and treat the change as a whole-file replace. */
    private const MAX_LCS_LINES = 4000;

    /**
     * @return array<int, array{index: int, lines: array<int, array{type: string, text: string}>}>
     */
    public static function hunks(string $current, string $latest): array
    {
        $old = self::lines($current);
        $new = self::lines($latest);
        $groups = self::groupOpcodes(self::opcodes($old, $new));

        $hunks = [];
        foreach ($groups as $i => $group) {
            $lines = [];
            foreach ($group as [$tag, $i1, $i2, $j1, $j2]) {
                if ($tag === 'equal') {
                    for ($k = $i1; $k < $i2; $k++) {
                        $lines[] = ['type' => 'context', 'text' => $old[$k]];
                    }
                } else {
                    for ($k = $i1; $k < $i2; $k++) {
                        $lines[] = ['type' => 'remove', 'text' => $old[$k]];
                    }
                    for ($k = $j1; $k < $j2; $k++) {
                        $lines[] = ['type' => 'add', 'text' => $new[$k]];
                    }
                }
            }
            $hunks[] = ['index' => $i, 'lines' => $lines];
        }

        return $hunks;
    }

    /**
     * @param  array<int, int>  $acceptedIndexes
     */
    public static function apply(string $current, string $latest, array $acceptedIndexes): string
    {
        $old = self::lines($current);
        $new = self::lines($latest);
        $opcodes = self::opcodes($old, $new);
        $hunkOf = self::opcodeHunkMap($opcodes);

        $out = [];
        foreach ($opcodes as $idx => [$tag, $i1, $i2, $j1, $j2]) {
            if ($tag === 'equal') {
                for ($k = $i1; $k < $i2; $k++) {
                    $out[] = $old[$k];
                }

                continue;
            }
            $accepted = isset($hunkOf[$idx]) && in_array($hunkOf[$idx], $acceptedIndexes, true);
            if ($accepted) {
                for ($k = $j1; $k < $j2; $k++) {
                    $out[] = $new[$k];
                }
            } else {
                for ($k = $i1; $k < $i2; $k++) {
                    $out[] = $old[$k];
                }
            }
        }

        return implode("\n", $out)."\n";
    }

    public static function isValidYaml(string $yaml): bool
    {
        try {
            Yaml::parse($yaml);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    private static function lines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", rtrim($text, "\n"));

        return $normalized === '' ? [] : explode("\n", $normalized);
    }

    /**
     * difflib-style opcodes via LCS.
     *
     * @param  array<int, string>  $old
     * @param  array<int, string>  $new
     * @return array<int, array{0:string,1:int,2:int,3:int,4:int}>
     */
    private static function opcodes(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        // Guard against the O(n·m) LCS matrix exhausting memory on very large
        // composes: present the change as a single whole-file replacement.
        if ($n > self::MAX_LCS_LINES || $m > self::MAX_LCS_LINES) {
            if ($n === 0) {
                return [['insert', 0, 0, 0, $m]];
            }
            if ($m === 0) {
                return [['delete', 0, $n, 0, 0]];
            }

            return [['replace', 0, $n, 0, $m]];
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $si = $i;
                $sj = $j;
                while ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                    $i++;
                    $j++;
                }
                $ops[] = ['equal', $si, $i, $sj, $j];
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['delete', $i, $i + 1, $j, $j];
                $i++;
            } else {
                $ops[] = ['insert', $i, $i, $j, $j + 1];
                $j++;
            }
        }
        if ($i < $n) {
            $ops[] = ['delete', $i, $n, $j, $j];
        }
        if ($j < $m) {
            $ops[] = ['insert', $i, $i, $j, $m];
        }

        return self::coalesce($ops);
    }

    /**
     * Merge adjacent delete+insert into replace.
     *
     * @param  array<int, array{0:string,1:int,2:int,3:int,4:int}>  $ops
     * @return array<int, array{0:string,1:int,2:int,3:int,4:int}>
     */
    private static function coalesce(array $ops): array
    {
        $merged = [];
        foreach ($ops as $op) {
            $last = end($merged);
            if ($last && $last[0] === 'delete' && $op[0] === 'insert') {
                $merged[count($merged) - 1] = ['replace', $last[1], $last[2], $op[3], $op[4]];

                continue;
            }
            $merged[] = $op;
        }

        return $merged;
    }

    /**
     * Group non-equal opcodes (with surrounding context) into display hunks.
     *
     * @param  array<int, array{0:string,1:int,2:int,3:int,4:int}>  $opcodes
     * @return array<int, array<int, array{0:string,1:int,2:int,3:int,4:int}>>
     */
    private static function groupOpcodes(array $opcodes): array
    {
        $groups = [];
        $current = [];
        $previousEqual = null;
        foreach ($opcodes as $op) {
            if ($op[0] === 'equal') {
                if ($current !== []) {
                    $ctx = min(self::CONTEXT, $op[2] - $op[1]);
                    $current[] = ['equal', $op[1], $op[1] + $ctx, $op[3], $op[3] + $ctx];
                    $groups[] = $current;
                    $current = [];
                }
                $previousEqual = $op;

                continue;
            }
            if ($current === [] && $previousEqual !== null) {
                $ctx = min(self::CONTEXT, $previousEqual[2] - $previousEqual[1]);
                if ($ctx > 0) {
                    $current[] = [
                        'equal',
                        $previousEqual[2] - $ctx, $previousEqual[2],
                        $previousEqual[4] - $ctx, $previousEqual[4],
                    ];
                }
            }
            $current[] = $op;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Map opcode index → hunk index, matching groupOpcodes()'s change grouping.
     *
     * @param  array<int, array{0:string,1:int,2:int,3:int,4:int}>  $opcodes
     * @return array<int, int>
     */
    private static function opcodeHunkMap(array $opcodes): array
    {
        $map = [];
        $hunk = -1;
        $inHunk = false;
        foreach ($opcodes as $idx => $op) {
            if ($op[0] === 'equal') {
                $inHunk = false;

                continue;
            }
            if (! $inHunk) {
                $hunk++;
                $inHunk = true;
            }
            $map[$idx] = $hunk;
        }

        return $map;
    }
}
