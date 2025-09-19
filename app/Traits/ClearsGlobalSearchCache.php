<?php

namespace App\Traits;

use App\Livewire\GlobalSearch;

trait ClearsGlobalSearchCache
{
    protected static function bootClearsGlobalSearchCache()
    {
        static::saved(function ($model) {
            // Clear search cache when model is saved
            $teamId = $model->getTeamIdForCache();
            if (filled($teamId)) {
                GlobalSearch::clearTeamCache($teamId);
            }
        });

        static::created(function ($model) {
            // Clear search cache when model is created
            $teamId = $model->getTeamIdForCache();
            if (filled($teamId)) {
                GlobalSearch::clearTeamCache($teamId);
            }
        });

        static::deleted(function ($model) {
            // Clear search cache when model is deleted
            $teamId = $model->getTeamIdForCache();
            if (filled($teamId)) {
                GlobalSearch::clearTeamCache($teamId);
            }
        });
    }

    private function getTeamIdForCache()
    {
        // For database models, team is accessed through environment.project.team
        if (method_exists($this, 'team')) {
            $team = $this->team();
            if (filled($team)) {
                return is_object($team) ? $team->id : null;
            }
        }

        // For models with direct team_id property
        if (property_exists($this, 'team_id') || isset($this->team_id)) {
            return $this->team_id;
        }

        return null;
    }
}
