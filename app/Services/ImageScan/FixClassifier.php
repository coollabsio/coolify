<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanFixState;

/**
 * Decides whether the user can fix a finding now, later, or not at all.
 */
final class FixClassifier
{
    private const OS_PACKAGE_TYPES = ['apk', 'deb', 'rpm'];

    /**
     * @param  list<string>  $candidateFixedIds  Vulnerability IDs that a newer candidate image fixes.
     */
    public function classify(Finding $finding, bool $buildsImage, bool $isBuilderImage, array $candidateFixedIds): ImageScanFixState
    {
        $hasPackageFix = $finding->fixState === 'fixed' && $finding->fixedIn !== null;

        if (! $buildsImage) {
            if ($this->isFixedByCandidate($finding, $candidateFixedIds)) {
                return ImageScanFixState::FixableNow;
            }

            return $hasPackageFix ? ImageScanFixState::NotReleased : ImageScanFixState::NoFix;
        }

        if (! $hasPackageFix) {
            return ImageScanFixState::NoFix;
        }

        if ($isBuilderImage && in_array($finding->type, self::OS_PACKAGE_TYPES, true)) {
            return ImageScanFixState::NotReleased;
        }

        return ImageScanFixState::FixableNow;
    }

    /**
     * @param  list<string>  $candidateFixedIds
     */
    private function isFixedByCandidate(Finding $finding, array $candidateFixedIds): bool
    {
        if ($candidateFixedIds === []) {
            return false;
        }

        $fixed = array_flip(array_map('mb_strtolower', $candidateFixedIds));

        foreach ([$finding->id, ...$finding->aliases] as $id) {
            if (isset($fixed[mb_strtolower($id)])) {
                return true;
            }
        }

        return false;
    }
}
