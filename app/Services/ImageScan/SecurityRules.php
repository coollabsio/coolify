<?php

namespace App\Services\ImageScan;

/**
 * Which findings matter (spec 15.2): relevant findings and the exploit signal.
 */
final class SecurityRules
{
    public const EXPLOITED = 'exploited';

    public const LIKELY = 'likely';

    private const LIKELY_EPSS = 0.10;

    private const LIKELY_SCORE = 7.0;

    private const SERIOUS_SEVERITIES = ['critical', 'high'];

    private const OS_PACKAGE_TYPES = ['apk', 'deb', 'rpm'];

    /**
     * `exploited` for a finding in CISA KEV; `likely` for EPSS 0.10 or more with CVSS 7.0 or more (severity high or
     * critical without a score); else null.
     */
    public static function exploitSignal(Finding $finding): ?string
    {
        if ($finding->kev) {
            return self::EXPLOITED;
        }

        $severe = $finding->cvssScore !== null
            ? $finding->cvssScore >= self::LIKELY_SCORE
            : self::isSerious($finding);

        return $finding->epss !== null && $finding->epss >= self::LIKELY_EPSS && $severe ? self::LIKELY : null;
    }

    /**
     * Not ignored, not a CPE-only OS match, and an exploit signal or severity critical or high.
     */
    public static function isRelevant(Finding $finding, IgnoreMatcher $ignores): bool
    {
        return ! self::isCpeOnlyOsMatch($finding)
            && (self::exploitSignal($finding) !== null || self::isSerious($finding))
            && $ignores->matches($finding) === null;
    }

    /**
     * An OS package (apk, deb, rpm) that Grype matched only by CPE.
     */
    public static function isCpeOnlyOsMatch(Finding $finding): bool
    {
        return str_starts_with(strtolower($finding->namespace), 'nvd:cpe')
            && in_array(strtolower($finding->type), self::OS_PACKAGE_TYPES, true);
    }

    /**
     * The IDs and aliases of the relevant findings, lowercase.
     *
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    public static function relevantIds(array $findings, IgnoreMatcher $ignores): array
    {
        $ids = [];
        foreach ($findings as $finding) {
            if (self::isRelevant($finding, $ignores)) {
                foreach ([$finding->id, ...$finding->aliases] as $id) {
                    if ($id !== '') {
                        $ids[] = mb_strtolower($id);
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function isSerious(Finding $finding): bool
    {
        return in_array(strtolower($finding->severity), self::SERIOUS_SEVERITIES, true);
    }
}
