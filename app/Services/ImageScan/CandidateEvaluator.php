<?php

namespace App\Services\ImageScan;

/**
 * Decides whether a candidate image is better than the current image (spec 15.4).
 */
final class CandidateEvaluator
{
    private const COUNTED_SEVERITIES = ['critical', 'high', 'medium'];

    /**
     * Better: fixes at least one relevant finding or leaves an end-of-life distro, and adds no finding with an
     * exploit signal and no critical finding. Ignores apply to both images.
     *
     * @param  list<Finding>  $currentFindings
     * @param  list<Finding>  $candidateFindings
     * @param  array<string, mixed>|null  $currentEol  The end-of-life distro of the current image.
     * @param  array<string, mixed>|null  $candidateEol  The end-of-life distro of the candidate.
     */
    public function isBetter(array $currentFindings, array $candidateFindings, IgnoreMatcher $ignores, ?array $currentEol, ?array $candidateEol): bool
    {
        if ($candidateEol !== null && $currentEol === null) {
            return false;
        }

        $current = [];
        foreach ($this->notIgnored($currentFindings, $ignores) as $finding) {
            foreach ($this->keys($finding) as $key) {
                $current[$key] = true;
            }
        }

        foreach ($this->relevant($candidateFindings, $ignores) as $finding) {
            $serious = SecurityRules::exploitSignal($finding) !== null || strtolower($finding->severity) === 'critical';
            if ($serious && array_intersect_key(array_flip($this->keys($finding)), $current) === []) {
                return false;
            }
        }

        return ($currentEol !== null && $candidateEol === null)
            || $this->fixed($this->relevant($currentFindings, $ignores), $candidateFindings) !== [];
    }

    /**
     * IDs and aliases of the relevant current findings that the candidate no longer has under any of their IDs.
     *
     * @param  list<Finding>  $current
     * @param  list<Finding>  $candidate  All findings of the candidate.
     * @return list<string>
     */
    public function fixedIds(array $current, array $candidate, IgnoreMatcher $ignores): array
    {
        $fixed = [];
        foreach ($this->fixed($this->relevant($current, $ignores), $candidate) as $finding) {
            array_push($fixed, ...$this->ids($finding));
        }

        return array_values(array_unique($fixed));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function relevant(array $findings, IgnoreMatcher $ignores): array
    {
        return array_values(array_filter($findings, fn (Finding $finding): bool => SecurityRules::isRelevant($finding, $ignores)));
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
     * The current findings that the other list also has under one of their IDs.
     *
     * @param  list<Finding>  $current
     * @param  list<Finding>  $other
     * @return list<Finding>
     */
    public function shared(array $current, array $other): array
    {
        $fixed = $this->fixed($current, $other);

        return array_values(array_filter($current, fn (Finding $finding) => ! in_array($finding, $fixed, true)));
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
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function notIgnored(array $findings, IgnoreMatcher $ignores): array
    {
        return array_values(array_filter($findings, fn (Finding $finding): bool => $ignores->matches($finding) === null));
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
