<?php

namespace App\Traits;

use App\Livewire\GlobalSearch;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

trait ClearsGlobalSearchCache
{
    protected static function bootClearsGlobalSearchCache()
    {
        static::saving(function ($model) {
            try {
                // Only clear cache if searchable fields are being changed
                if ($model->hasSearchableChanges()) {
                    $teamId = $model->getTeamIdForCache();
                    if (filled($teamId)) {
                        GlobalSearch::clearTeamCache($teamId);
                    }
                }
            } catch (\Throwable $e) {
                // Silently fail cache clearing - don't break the save operation
            }
        });

        static::created(function ($model) {
            try {
                // Always clear cache when model is created
                $teamId = $model->getTeamIdForCache();
                if (filled($teamId)) {
                    GlobalSearch::clearTeamCache($teamId);
                }
            } catch (\Throwable $e) {
                // Silently fail cache clearing - don't break the create operation
            }
        });

        static::deleted(function ($model) {
            try {
                // Always clear cache when model is deleted
                $teamId = $model->getTeamIdForCache();
                if (filled($teamId)) {
                    GlobalSearch::clearTeamCache($teamId);
                }
            } catch (\Throwable $e) {
                // Silently fail cache clearing - don't break the delete operation
            }
        });
    }

    private function hasSearchableChanges(): bool
    {
        try {
            // Define searchable fields based on model type
            $searchableFields = ['name', 'description'];

            // Add model-specific searchable fields
            if ($this instanceof Application) {
                $searchableFields[] = 'fqdn';
                $searchableFields[] = 'docker_compose_domains';
            } elseif ($this instanceof Server) {
                $searchableFields[] = 'ip';
            } elseif ($this instanceof Service) {
                // Services don't have direct fqdn, but name and description are covered
            } elseif ($this instanceof Project || $this instanceof Environment) {
                // Projects and environments only have name and description as searchable
            }
            // Database models only have name and description as searchable

            // Check if any searchable field is dirty
            foreach ($searchableFields as $field) {
                // Check if attribute exists before checking if dirty
                if (array_key_exists($field, $this->getAttributes()) && $this->isDirty($field)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            // If checking changes fails, assume changes exist to be safe

            return true;
        }
    }

    private function getTeamIdForCache()
    {
        try {
            // For Project models (has direct team_id)
            if ($this instanceof Project) {
                return $this->team_id ?? null;
            }

            // For Environment models (get team_id through project)
            if ($this instanceof Environment) {
                return $this->project?->team_id;
            }

            // For database models, team is accessed through environment.project.team
            if (method_exists($this, 'team')) {
                if ($this instanceof Server) {
                    $team = $this->team;
                } else {
                    $team = $this->team();
                }
                if (filled($team)) {
                    return is_object($team) ? $team->id : null;
                }
            }

            // For models with direct team_id property
            if (property_exists($this, 'team_id') || isset($this->team_id)) {
                return $this->team_id ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            // If we can't determine team ID, return null

            return null;
        }
    }
}
