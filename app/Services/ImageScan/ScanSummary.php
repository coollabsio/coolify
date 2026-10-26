<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanFixState;

/**
 * Counts findings by fix state and severity for the resource page, the gate, and notifications.
 */
final class ScanSummary
{
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /**
     * @param  list<Finding>  $findings
     * @param  callable(Finding): ImageScanFixState  $classify
     * @param  array{name: string, version: string}|null  $eol
     * @return array{counts: array<string, array<string, int>>, fixable_now_at_level: int, known_exploited_fixable: int, ignored: int, eol_distro: array{name: string, version: string}|null, truncated: bool}
     */
    public static function build(array $findings, callable $classify, IgnoreMatcher $ignores, ImageScanAlertLevel $level, ?array $eol = null, bool $truncated = false): array
    {
        $empty = array_fill_keys(self::SEVERITIES, 0);
        $counts = [];

        foreach (ImageScanFixState::cases() as $state) {
            $counts[$state->value] = $empty;
        }

        $fixableAtLevel = 0;
        $knownExploitedFixable = 0;
        $ignored = 0;

        foreach ($findings as $finding) {
            if ($ignores->matches($finding) !== null) {
                $ignored++;

                continue;
            }

            $state = $classify($finding);
            $severity = in_array($finding->severity, self::SEVERITIES, true) ? $finding->severity : 'unknown';
            $counts[$state->value][$severity]++;

            if ($state === ImageScanFixState::FixableNow) {
                if ($level->includes($finding->severity)) {
                    $fixableAtLevel++;
                }

                if ($finding->kev) {
                    $knownExploitedFixable++;
                }
            }
        }

        return [
            'counts' => $counts,
            'fixable_now_at_level' => $fixableAtLevel,
            'known_exploited_fixable' => $knownExploitedFixable,
            'ignored' => $ignored,
            'eol_distro' => $eol,
            'truncated' => $truncated,
        ];
    }
}
