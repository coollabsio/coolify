<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanAlertLevel;

/**
 * Decides whether a candidate image is better than the current image (spec 11, candidate rules).
 */
final class CandidateEvaluator
{
    private const COUNTED_SEVERITIES = ['critical', 'high', 'medium'];

    /**
     * Findings at or above the alert level (fixable or not), their counts, and the IDs of known exploited findings.
     *
     * @param  list<Finding>  $findings
     * @return array{at_level: int, counts: array{critical: int, high: int, medium: int}, kev_ids: list<string>}
     */
    public function summarize(array $findings, ImageScanAlertLevel $level): array
    {
        $kevIds = [];
        foreach ($findings as $finding) {
            if ($finding->kev) {
                array_push($kevIds, ...$this->keys($finding));
            }
        }

        $atLevel = $this->atLevel($findings, $level);

        return [
            'at_level' => count($atLevel),
            'counts' => $this->counts($atLevel),
            'kev_ids' => array_values(array_unique($kevIds)),
        ];
    }

    /**
     * Better: fewer findings at or above the alert level in total, no new known exploited finding, and not
     * an end-of-life distro when the current distro still gets fixes.
     *
     * @param  array{at_level: int, kev_ids: list<string>}  $currentSummary  From summarize().
     * @param  list<Finding>  $candidateFindings
     */
    public function isBetter(array $currentSummary, array $candidateFindings, ImageScanAlertLevel $level, bool $currentEol, bool $candidateEol): bool
    {
        if ($candidateEol && ! $currentEol) {
            return false;
        }

        if (count($this->atLevel($candidateFindings, $level)) >= $currentSummary['at_level']) {
            return false;
        }

        $currentKev = array_flip(array_map('mb_strtolower', $currentSummary['kev_ids']));
        foreach ($candidateFindings as $finding) {
            if ($finding->kev && array_intersect_key(array_flip($this->keys($finding)), $currentKev) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * IDs and aliases of the current findings that the candidate no longer has under any of their IDs.
     *
     * @param  list<Finding>  $current  The current findings to check, usually those at the alert level.
     * @param  list<Finding>  $candidate  All findings of the candidate.
     * @return list<string>
     */
    public function fixedIds(array $current, array $candidate): array
    {
        $fixed = [];
        foreach ($this->fixed($current, $candidate) as $finding) {
            array_push($fixed, ...$this->ids($finding));
        }

        return array_values(array_unique($fixed));
    }

    /**
     * The current findings that the candidate no longer has.
     *
     * @param  list<Finding>  $current
     * @param  list<Finding>  $candidate
     * @return list<Finding>
     */
    public function fixed(array $current, array $candidate): array
    {
        $remaining = [];
        foreach ($candidate as $finding) {
            foreach ($this->keys($finding) as $key) {
                $remaining[$key] = true;
            }
        }

        return array_values(array_filter($current, fn (Finding $finding) => array_intersect_key(array_flip($this->keys($finding)), $remaining) === []));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function atLevel(array $findings, ImageScanAlertLevel $level): array
    {
        return array_values(array_filter($findings, fn (Finding $finding) => $level->includes($finding->severity)));
    }

    /**
     * @param  list<Finding>  $findings
     * @return array{critical: int, high: int, medium: int}
     */
    public function counts(array $findings): array
    {
        $counts = array_fill_keys(self::COUNTED_SEVERITIES, 0);
        foreach ($findings as $finding) {
            $severity = strtolower($finding->severity);
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function ids(Finding $finding): array
    {
        return array_values(array_unique(array_filter([$finding->id, ...$finding->aliases], fn (string $id) => $id !== '')));
    }

    /**
     * IDs compare without case, like ignores and the fix classifier.
     *
     * @return list<string>
     */
    private function keys(Finding $finding): array
    {
        return array_values(array_unique(array_map('mb_strtolower', $this->ids($finding))));
    }
}
