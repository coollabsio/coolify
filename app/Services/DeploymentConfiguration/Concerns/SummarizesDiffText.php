<?php

namespace App\Services\DeploymentConfiguration\Concerns;

trait SummarizesDiffText
{
    /**
     * Maximum length of a single-line value before it is truncated/considered
     * worth expanding. Kept as one constant so the snapshot summary and the
     * differ's expand decision never drift apart.
     */
    private const SINGLE_LINE_LIMIT = 120;

    /**
     * Returns the value only when it is worth expanding (multi-line or longer
     * than the single-line truncation limit). Otherwise null.
     */
    private function expandableText(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (str_contains($value, "\n") || mb_strlen($value) > self::SINGLE_LINE_LIMIT) {
            return $value;
        }

        return null;
    }
}
