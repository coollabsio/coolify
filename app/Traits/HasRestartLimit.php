<?php

namespace App\Traits;

use App\Services\RestartCountTracker;

trait HasRestartLimit
{
    public function initializeHasRestartLimit(): void
    {
        $this->mergeFillable(['restart_count', 'max_restart_count', 'restart_limit_reached', 'last_restart_at', 'last_restart_type']);
        $this->mergeCasts([
            'restart_count' => 'integer',
            'max_restart_count' => 'integer',
            'restart_limit_reached' => 'boolean',
            'last_restart_at' => 'datetime',
            'last_restart_type' => 'string',
        ]);
    }

    public function stoppedAfterRestartLimit(): bool
    {
        return str($this->status)->startsWith('exited') && $this->restart_limit_reached === true;
    }

    public function trackRestartCount(int $observedRestartCount): bool
    {
        $state = (new RestartCountTracker)->evaluate(
            previousRestartCount: $this->restart_count ?? 0,
            observedRestartCount: $observedRestartCount,
            maxRestartCount: $this->restartLimitMaximum(),
        );

        if ($state['restart_count_changed']) {
            $hasCrashRestarts = $state['restart_count'] > 0;
            $this->update([
                'restart_count' => $state['restart_count'],
                'last_restart_at' => $hasCrashRestarts ? now() : null,
                'last_restart_type' => $hasCrashRestarts ? 'crash' : null,
            ]);
        }

        if (! $state['restart_limit_reached']) {
            return false;
        }

        $claimed = $this->newQuery()
            ->whereKey($this->getKey())
            ->where('restart_limit_reached', false)
            ->update(['restart_limit_reached' => true]) === 1;

        if ($claimed) {
            $this->restart_limit_reached = true;
        }

        return $claimed;
    }

    public function resetRestartLimit(): void
    {
        $this->update([
            'restart_count' => 0,
            'restart_limit_reached' => false,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);
    }

    public function restartLimitMaximum(): int
    {
        return $this->max_restart_count ?? 0;
    }
}
