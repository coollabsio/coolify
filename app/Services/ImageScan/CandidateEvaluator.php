<?php

namespace App\Services\ImageScan;

/**
 * Decides whether a candidate image is better than the current image (spec 15.4).
 */
final class CandidateEvaluator
{
    private const COUNTED_SEVERITIES = ['critical', 'high', 'medium'];

    /**
     * Better: adds no finding with an exploit signal, and fixes a finding with an exploit signal, leaves an end-of-life
     * distro, or has fewer relevant findings (an end-of-life distro counts as one). Ignores apply to both images.
     *
     * @param  list<Finding>  $currentFindings
     * @param  list<Finding>  $candidateFindings
     * @param  array<string, mixed>|null  $currentEol  The end-of-life distro of the current image.
     * @param  array<string, mixed>|null  $candidateEol  The end-of-life distro of the candidate.
     */
    public function isBetter(array $currentFindings, array $candidateFindings, IgnoreMatcher $ignores, ?array $currentEol, ?array $candidateEol): bool
    {
        $current = [];
        foreach ($this->notIgnored($currentFindings, $ignores) as $finding) {
            foreach ($this->keys($finding) as $key) {
                $current[$key] = true;
            }
        }

        $candidateRelevant = $this->relevant($candidateFindings, $ignores);
        foreach ($candidateRelevant as $finding) {
            if (SecurityRules::exploitSignal($finding) !== null && array_intersect_key(array_flip($this->keys($finding)), $current) === []) {
                return false;
            }
        }

        $currentRelevant = $this->relevant($currentFindings, $ignores);
        $fixesExploit = array_filter($this->fixed($currentRelevant, $candidateFindings), fn (Finding $finding): bool => SecurityRules::exploitSignal($finding) !== null) !== [];

        return $fixesExploit
            || ($currentEol !== null && $candidateEol === null)
            || count($candidateRelevant) + ($candidateEol === null ? 0 : 1) < count($currentRelevant) + ($currentEol === null ? 0 : 1);
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
     * The relevant critical, high, and medium findings of the candidate that the relevant current findings do not have
     * under any of their IDs, counted once per finding ID. The same basis as `counts()` of `fixed()`.
     *
     * @param  list<Finding>  $currentRelevant
     * @param  list<Finding>  $candidate  All findings of the candidate.
     */
    public function added(array $currentRelevant, array $candidate, IgnoreMatcher $ignores): int
    {
        $known = [];
        foreach ($currentRelevant as $finding) {
            foreach ($this->keys($finding) as $key) {
                $known[$key] = true;
            }
        }

        $added = [];
        foreach ($this->relevant($candidate, $ignores) as $finding) {
            if (in_array(strtolower($finding->severity), self::COUNTED_SEVERITIES, true)
                && array_intersect_key(array_flip($this->keys($finding)), $known) === []) {
                $added[mb_strtolower($finding->id)] = true;
            }
        }

        return count($added);
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
