<?php

namespace App\Services;

class RestartCountTracker
{
    /**
     * @return array{restart_count: int, restart_count_changed: bool, restart_limit_reached: bool, new_generation: bool}
     */
    public function evaluate(
        int $previousRestartCount,
        int $observedRestartCount,
        int $maxRestartCount,
        bool $newGenerationConfirmed = false,
    ): array {
        $newGeneration = $newGenerationConfirmed
            && $observedRestartCount < $previousRestartCount;
        $restartCountIncreased = $observedRestartCount > $previousRestartCount;
        $restartCountChanged = $newGeneration || $restartCountIncreased;

        $restartLimitReached = $maxRestartCount > 0
            && $observedRestartCount >= $maxRestartCount;

        return [
            'restart_count' => $restartCountChanged ? $observedRestartCount : $previousRestartCount,
            'restart_count_changed' => $restartCountChanged,
            'restart_limit_reached' => $restartLimitReached,
            'new_generation' => $newGeneration,
        ];
    }
}
