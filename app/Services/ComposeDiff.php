<?php

namespace App\Services;

class ComposeDiff
{
    private const CONTEXT = 3;

    /** Above this many LCS matrix cells (~8 MB), treat the changed region as one replacement. */
    private const MAX_LCS_CELLS = 500_000;

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

    /** @return array<int, string> */
    private static function lines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", rtrim($text, "\n"));

        return $normalized === '' ? [] : explode("\n", $normalized);
    }

    /**
     * difflib-style opcodes. Equal leading and trailing lines are stripped first, so
     * the LCS matrix only covers the changed middle of the file.
     *
     * @param  array<int, string>  $old
     * @param  array<int, string>  $new
     * @return array<int, array{0:string,1:int,2:int,3:int,4:int}>
     */
    private static function opcodes(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $oldCount - $prefix && $suffix < $newCount - $prefix
            && $old[$oldCount - 1 - $suffix] === $new[$newCount - 1 - $suffix]) {
            $suffix++;
        }

        $ops = $prefix > 0 ? [['equal', 0, $prefix, 0, $prefix]] : [];
        array_push($ops, ...self::middleOpcodes($old, $new, $prefix, $oldCount - $suffix, $prefix, $newCount - $suffix));
        if ($suffix > 0) {
            $ops[] = ['equal', $oldCount - $suffix, $oldCount, $newCount - $suffix, $newCount];
        }

        return $ops;
    }

    /**
     * LCS opcodes for $old[$oldStart..$oldEnd) against $new[$newStart..$newEnd).
     *
     * @param  array<int, string>  $old
     * @param  array<int, string>  $new
     * @return array<int, array{0:string,1:int,2:int,3:int,4:int}>
     */
    private static function middleOpcodes(array $old, array $new, int $oldStart, int $oldEnd, int $newStart, int $newEnd): array
    {
        $rows = $oldEnd - $oldStart;
        $cols = $newEnd - $newStart;

        if ($rows === 0 && $cols === 0) {
            return [];
        }
        if ($rows === 0) {
            return [['insert', $oldStart, $oldStart, $newStart, $newEnd]];
        }
        if ($cols === 0) {
            return [['delete', $oldStart, $oldEnd, $newStart, $newStart]];
        }
        // The LCS matrix is O(rows·cols) memory: show huge changes as one replacement.
        if ($rows * $cols > self::MAX_LCS_CELLS) {
            return [['replace', $oldStart, $oldEnd, $newStart, $newEnd]];
        }

        $lcs = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
        for ($row = $rows - 1; $row >= 0; $row--) {
            for ($col = $cols - 1; $col >= 0; $col--) {
                $lcs[$row][$col] = $old[$oldStart + $row] === $new[$newStart + $col]
                    ? $lcs[$row + 1][$col + 1] + 1
                    : max($lcs[$row + 1][$col], $lcs[$row][$col + 1]);
            }
        }

        $ops = [];
        $row = 0;
        $col = 0;
        while ($row < $rows && $col < $cols) {
            if ($old[$oldStart + $row] === $new[$newStart + $col]) {
                $equalRowStart = $row;
                $equalColStart = $col;
                while ($row < $rows && $col < $cols && $old[$oldStart + $row] === $new[$newStart + $col]) {
                    $row++;
                    $col++;
                }
                $ops[] = ['equal', $oldStart + $equalRowStart, $oldStart + $row, $newStart + $equalColStart, $newStart + $col];
            } elseif ($lcs[$row + 1][$col] >= $lcs[$row][$col + 1]) {
                $ops[] = ['delete', $oldStart + $row, $oldStart + $row + 1, $newStart + $col, $newStart + $col];
                $row++;
            } else {
                $ops[] = ['insert', $oldStart + $row, $oldStart + $row, $newStart + $col, $newStart + $col + 1];
                $col++;
            }
        }
        if ($row < $rows) {
            $ops[] = ['delete', $oldStart + $row, $oldEnd, $newStart + $col, $newStart + $col];
        }
        if ($col < $cols) {
            $ops[] = ['insert', $oldStart + $row, $oldStart + $row, $newStart + $col, $newEnd];
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
