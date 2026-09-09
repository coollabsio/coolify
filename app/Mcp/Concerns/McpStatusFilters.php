<?php

namespace App\Mcp\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait McpStatusFilters
{
    /**
     * SQL predicate for resources whose stored status is not a healthy running state.
     *
     * Matches empty/null status, non-running prefixes, and running-but-unhealthy/degraded.
     */
    protected function scopeNotHealthyRunning(Builder $query, string $column = 'status'): Builder
    {
        return $query->where(function (Builder $q) use ($column) {
            $q->whereNull($column)
                ->orWhere($column, '')
                ->orWhereRaw("LOWER({$column}) NOT LIKE ?", ['running%'])
                ->orWhereRaw("LOWER({$column}) LIKE ?", ['%unhealthy%'])
                ->orWhereRaw("LOWER({$column}) LIKE ?", ['%degraded%'])
                ->orWhereRaw("LOWER({$column}) LIKE ?", ['%restarting%']);
        });
    }

    protected function looksHealthy(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return false;
        }

        $s = strtolower($status);

        if (str_contains($s, 'unhealthy') || str_contains($s, 'degraded') || str_contains($s, 'exited') || str_contains($s, 'restarting')) {
            return false;
        }

        return str_starts_with($s, 'running');
    }
}
