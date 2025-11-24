<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Container Status Aggregator Service
 *
 * Centralized service for aggregating container statuses into a single status string.
 * Uses a priority-based state machine to determine the overall status from multiple containers.
 *
 * Output Format: Colon-separated (e.g., "running:healthy", "degraded:unhealthy")
 * This format is used throughout the backend for consistency and machine-readability.
 * UI components transform this to human-readable format (e.g., "Running (Healthy)").
 *
 * State Priority (highest to lowest):
 * 1. Restarting → degraded:unhealthy
 * 2. Crash Loop (exited with restarts) → degraded:unhealthy
 * 3. Mixed (running + exited) → degraded:unhealthy
 * 4. Running → running:healthy/unhealthy/unknown
 * 5. Dead/Removing → degraded:unhealthy
 * 6. Paused → paused:unknown
 * 7. Starting/Created → starting:unknown
 * 8. Exited → exited
 */
class ContainerStatusAggregator
{
    /**
     * Aggregate container statuses from status strings into a single status.
     *
     * @param  Collection  $containerStatuses  Collection of status strings (e.g., "running (healthy)", "running:healthy")
     * @param  int  $maxRestartCount  Maximum restart count across containers (for crash loop detection)
     * @return string Aggregated status in colon format (e.g., "running:healthy")
     */
    public function aggregateFromStrings(Collection $containerStatuses, int $maxRestartCount = 0): string
    {
        // Validate maxRestartCount parameter
        if ($maxRestartCount < 0) {
            Log::warning('Negative maxRestartCount corrected to 0', [
                'original_value' => $maxRestartCount,
            ]);
            $maxRestartCount = 0;
        }

        if ($maxRestartCount > 1000) {
            Log::warning('High maxRestartCount detected', [
                'maxRestartCount' => $maxRestartCount,
                'containers' => $containerStatuses->count(),
            ]);
        }

        if ($containerStatuses->isEmpty()) {
            return 'exited';
        }

        // Initialize state flags
        $hasRunning = false;
        $hasRestarting = false;
        $hasUnhealthy = false;
        $hasUnknown = false;
        $hasExited = false;
        $hasStarting = false;
        $hasPaused = false;
        $hasDead = false;

        // Parse each status string and set flags
        foreach ($containerStatuses as $status) {
            if (str($status)->contains('restarting')) {
                $hasRestarting = true;
            } elseif (str($status)->contains('running')) {
                $hasRunning = true;
                if (str($status)->contains('unhealthy')) {
                    $hasUnhealthy = true;
                }
                if (str($status)->contains('unknown')) {
                    $hasUnknown = true;
                }
            } elseif (str($status)->contains('exited')) {
                $hasExited = true;
            } elseif (str($status)->contains('created') || str($status)->contains('starting')) {
                $hasStarting = true;
            } elseif (str($status)->contains('paused')) {
                $hasPaused = true;
            } elseif (str($status)->contains('dead') || str($status)->contains('removing')) {
                $hasDead = true;
            }
        }

        // Priority-based status resolution
        return $this->resolveStatus(
            $hasRunning,
            $hasRestarting,
            $hasUnhealthy,
            $hasUnknown,
            $hasExited,
            $hasStarting,
            $hasPaused,
            $hasDead,
            $maxRestartCount
        );
    }

    /**
     * Aggregate container statuses from Docker container objects.
     *
     * @param  Collection  $containers  Collection of Docker container objects with State property
     * @param  int  $maxRestartCount  Maximum restart count across containers (for crash loop detection)
     * @return string Aggregated status in colon format (e.g., "running:healthy")
     */
    public function aggregateFromContainers(Collection $containers, int $maxRestartCount = 0): string
    {
        // Validate maxRestartCount parameter
        if ($maxRestartCount < 0) {
            Log::warning('Negative maxRestartCount corrected to 0', [
                'original_value' => $maxRestartCount,
            ]);
            $maxRestartCount = 0;
        }

        if ($maxRestartCount > 1000) {
            Log::warning('High maxRestartCount detected', [
                'maxRestartCount' => $maxRestartCount,
                'containers' => $containers->count(),
            ]);
        }

        if ($containers->isEmpty()) {
            return 'exited';
        }

        // Initialize state flags
        $hasRunning = false;
        $hasRestarting = false;
        $hasUnhealthy = false;
        $hasUnknown = false;
        $hasExited = false;
        $hasStarting = false;
        $hasPaused = false;
        $hasDead = false;

        // Parse each container object and set flags
        foreach ($containers as $container) {
            $state = data_get($container, 'State.Status', 'exited');
            $health = data_get($container, 'State.Health.Status');

            if ($state === 'restarting') {
                $hasRestarting = true;
            } elseif ($state === 'running') {
                $hasRunning = true;
                if ($health === 'unhealthy') {
                    $hasUnhealthy = true;
                } elseif (is_null($health) || $health === 'starting') {
                    $hasUnknown = true;
                }
            } elseif ($state === 'exited') {
                $hasExited = true;
            } elseif ($state === 'created' || $state === 'starting') {
                $hasStarting = true;
            } elseif ($state === 'paused') {
                $hasPaused = true;
            } elseif ($state === 'dead' || $state === 'removing') {
                $hasDead = true;
            }
        }

        // Priority-based status resolution
        return $this->resolveStatus(
            $hasRunning,
            $hasRestarting,
            $hasUnhealthy,
            $hasUnknown,
            $hasExited,
            $hasStarting,
            $hasPaused,
            $hasDead,
            $maxRestartCount
        );
    }

    /**
     * Resolve the aggregated status based on state flags (priority-based state machine).
     *
     * @param  bool  $hasRunning  Has at least one running container
     * @param  bool  $hasRestarting  Has at least one restarting container
     * @param  bool  $hasUnhealthy  Has at least one unhealthy container
     * @param  bool  $hasUnknown  Has at least one container with unknown health
     * @param  bool  $hasExited  Has at least one exited container
     * @param  bool  $hasStarting  Has at least one starting/created container
     * @param  bool  $hasPaused  Has at least one paused container
     * @param  bool  $hasDead  Has at least one dead/removing container
     * @param  int  $maxRestartCount  Maximum restart count (for crash loop detection)
     * @return string Status in colon format (e.g., "running:healthy")
     */
    private function resolveStatus(
        bool $hasRunning,
        bool $hasRestarting,
        bool $hasUnhealthy,
        bool $hasUnknown,
        bool $hasExited,
        bool $hasStarting,
        bool $hasPaused,
        bool $hasDead,
        int $maxRestartCount
    ): string {
        // Priority 1: Restarting containers (degraded state)
        if ($hasRestarting) {
            return 'degraded:unhealthy';
        }

        // Priority 2: Crash loop detection (exited with restart count > 0)
        if ($hasExited && $maxRestartCount > 0) {
            return 'degraded:unhealthy';
        }

        // Priority 3: Mixed state (some running, some exited = degraded)
        if ($hasRunning && $hasExited) {
            return 'degraded:unhealthy';
        }

        // Priority 4: Running containers (check health status)
        if ($hasRunning) {
            if ($hasUnhealthy) {
                return 'running:unhealthy';
            } elseif ($hasUnknown) {
                return 'running:unknown';
            } else {
                return 'running:healthy';
            }
        }

        // Priority 5: Dead or removing containers
        if ($hasDead) {
            return 'degraded:unhealthy';
        }

        // Priority 6: Paused containers
        if ($hasPaused) {
            return 'paused:unknown';
        }

        // Priority 7: Starting/created containers
        if ($hasStarting) {
            return 'starting:unknown';
        }

        // Priority 8: All containers exited (no restart count = truly stopped)
        return 'exited';
    }
}
