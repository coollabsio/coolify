<?php

namespace App\Traits;

use App\Livewire\GlobalSearch;

trait ClearsGlobalSearchCache
{
    protected static function bootClearsGlobalSearchCache()
    {
        static::saving(function ($model) {
            // Only clear cache if searchable fields are being changed
            if ($model->hasSearchableChanges()) {
                $teamId = $model->getTeamIdForCache();
                if (filled($teamId)) {
                    GlobalSearch::clearTeamCache($teamId);
                }
            }
        });

        static::created(function ($model) {
            // Always clear cache when model is created
            $teamId = $model->getTeamIdForCache();
            if (filled($teamId)) {
                GlobalSearch::clearTeamCache($teamId);
            }
        });

        static::deleted(function ($model) {
            // Always clear cache when model is deleted
            $teamId = $model->getTeamIdForCache();
            if (filled($teamId)) {
                GlobalSearch::clearTeamCache($teamId);
            }
        });
    }

    private function hasSearchableChanges(): bool
    {
        // Define searchable fields based on model type
        $searchableFields = ['name', 'description'];

        // Add model-specific searchable fields
        if ($this instanceof \App\Models\Application) {
            $searchableFields[] = 'fqdn';
            $searchableFields[] = 'docker_compose_domains';
        } elseif ($this instanceof \App\Models\Server) {
            $searchableFields[] = 'ip';
        } elseif ($this instanceof \App\Models\Service) {
            // Services don't have direct fqdn, but name and description are covered
        }
        // Database models only have name and description as searchable

        // Check if any searchable field is dirty
        foreach ($searchableFields as $field) {
            if ($this->isDirty($field)) {
                return true;
            }
        }

        return false;
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
