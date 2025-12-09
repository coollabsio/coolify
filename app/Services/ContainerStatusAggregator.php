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
 * 1. Degraded (from sub-resources) → degraded:unhealthy
 * 2. Restarting → degraded:unhealthy (or restarting:unknown if preserveRestarting=true)
 * 3. Crash Loop (exited with restarts) → degraded:unhealthy
 * 4. Mixed (running + exited) → degraded:unhealthy
 * 5. Mixed (running + starting) → starting:unknown
 * 6. Running → running:healthy/unhealthy/unknown
 * 7. Dead/Removing → degraded:unhealthy
 * 8. Paused → paused:unknown
 * 9. Starting/Created → starting:unknown
 * 10. Exited → exited
 *
 * The $preserveRestarting parameter controls whether "restarting" containers should be
 * reported as "restarting:unknown" (true) or "degraded:unhealthy" (false, default).
 * - Use preserveRestarting=true for individual sub-resources (ServiceApplication/ServiceDatabase)
 *   so they show "Restarting" in the UI.
 * - Use preserveRestarting=false for overall Service status aggregation where any restarting
 *   container should mark the entire service as "Degraded".
 */
class ContainerStatusAggregator
{
    /**
     * Aggregate container statuses from status strings into a single status.
     *
     * @param  Collection  $containerStatuses  Collection of status strings (e.g., "running (healthy)", "running:healthy")
     * @param  int  $maxRestartCount  Maximum restart count across containers (for crash loop detection)
     * @param  bool  $preserveRestarting  If true, "restarting" containers return "restarting:unknown" instead of "degraded:unhealthy"
     * @return string Aggregated status in colon format (e.g., "running:healthy")
     */
    public function aggregateFromStrings(Collection $containerStatuses, int $maxRestartCount = 0, bool $preserveRestarting = false): string
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
        $hasDegraded = false;

        // Parse each status string and set flags
        foreach ($containerStatuses as $status) {
            if (str($status)->contains('degraded')) {
                $hasDegraded = true;
                if (str($status)->contains('unhealthy')) {
                    $hasUnhealthy = true;
                }
            } elseif (str($status)->contains('restarting')) {
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
            $hasDegraded,
            $maxRestartCount,
            $preserveRestarting
        );
    }

    /**
     * Aggregate container statuses from Docker container objects.
     *
     * @param  Collection  $containers  Collection of Docker container objects with State property
     * @param  int  $maxRestartCount  Maximum restart count across containers (for crash loop detection)
     * @param  bool  $preserveRestarting  If true, "restarting" containers return "restarting:unknown" instead of "degraded:unhealthy"
     * @return string Aggregated status in colon format (e.g., "running:healthy")
     */
    public function aggregateFromContainers(Collection $containers, int $maxRestartCount = 0, bool $preserveRestarting = false): string
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
            false, // $hasDegraded - not applicable for container objects, only for status strings
            $maxRestartCount,
            $preserveRestarting
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
     * @param  bool  $hasDegraded  Has at least one degraded container
     * @param  int  $maxRestartCount  Maximum restart count (for crash loop detection)
     * @param  bool  $preserveRestarting  If true, return "restarting:unknown" instead of "degraded:unhealthy" for restarting containers
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
        bool $hasDegraded,
        int $maxRestartCount,
        bool $preserveRestarting = false
    ): string {
        // Priority 1: Degraded containers from sub-resources (highest priority)
        // If any service/application within a service stack is degraded, the entire stack is degraded
        if ($hasDegraded) {
            return 'degraded:unhealthy';
        }

        // Priority 2: Restarting containers
        // When preserveRestarting is true (for individual sub-resources), keep as "restarting"
        // When false (for overall service status), mark as "degraded"
        if ($hasRestarting) {
            return $preserveRestarting ? 'restarting:unknown' : 'degraded:unhealthy';
        }

        // Priority 3: Crash loop detection (exited with restart count > 0)
        if ($hasExited && $maxRestartCount > 0) {
            return 'degraded:unhealthy';
        }

        // Priority 4: Mixed state (some running, some exited = degraded)
        if ($hasRunning && $hasExited) {
            return 'degraded:unhealthy';
        }

        // Priority 5: Mixed state (some running, some starting = still starting)
        // If any component is still starting, the entire service stack is not fully ready
        if ($hasRunning && $hasStarting) {
            return 'starting:unknown';
        }

        // Priority 6: Running containers (check health status)
        if ($hasRunning) {
            if ($hasUnhealthy) {
                return 'running:unhealthy';
            } elseif ($hasUnknown) {
                return 'running:unknown';
            } else {
                return 'running:healthy';
            }
        }

        // Priority 7: Dead or removing containers
        if ($hasDead) {
            return 'degraded:unhealthy';
        }

        // Priority 8: Paused containers
        if ($hasPaused) {
            return 'paused:unknown';
        }

        // Priority 9: Starting/created containers
        if ($hasStarting) {
            return 'starting:unknown';
        }

        // Priority 10: All containers exited (no restart count = truly stopped)
        return 'exited';
    }
}
