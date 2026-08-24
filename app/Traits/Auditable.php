<?php

namespace App\Traits;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait Auditable
{
    private bool $auditLoggingEnabled = true;

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->recordAuditMutation('created'));
        static::updated(fn (Model $model) => $model->recordAuditMutation('updated'));
        static::deleted(fn (Model $model) => $model->recordAuditMutation('deleted'));
    }

    private function recordAuditMutation(string $action): void
    {
        if (! $this->auditLoggingEnabled || ! auth()->check()) {
            return;
        }

        $teamId = $this->auditTeamId();
        if ($teamId === null) {
            return;
        }

        $changedFields = $action === 'updated'
            ? collect(array_keys($this->getChanges()))
                ->reject(fn (string $field): bool => in_array($field, [
                    'updated_at',
                    'order',
                    'status',
                    ...($this->auditExclude ?? []),
                ], true))
                ->values()
                ->all()
            : [];

        if ($action === 'updated' && $changedFields === []) {
            return;
        }

        $resourceType = Str::snake(class_basename($this));
        $source = auth()->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'ui';

        auditLog("{$source}.{$resourceType}.{$action}", [
            'team_id' => $teamId,
            "{$resourceType}_uuid" => $this->getAttribute('uuid'),
            "{$resourceType}_name" => $this->getAttribute('name') ?? $this->getAttribute('key'),
            'changed_fields' => $changedFields,
        ]);
    }

    public function withoutAuditLogging(Closure $callback): mixed
    {
        $this->auditLoggingEnabled = false;

        try {
            return $callback();
        } finally {
            $this->auditLoggingEnabled = true;
        }
    }

    private function auditTeamId(): ?int
    {
        if ($this instanceof Team) {
            return (int) $this->getKey();
        }

        if ($this->getAttribute('team_id') !== null) {
            return (int) $this->getAttribute('team_id');
        }

        if ($this->getAttribute('project_id') !== null) {
            return $this->project?->team_id;
        }

        if ($this->getAttribute('environment_id') !== null) {
            return $this->environment?->project?->team_id;
        }

        if ($this->getAttribute('server_id') !== null) {
            return $this->server?->team_id;
        }

        if ($this->getAttribute('resourceable_id') !== null) {
            return $this->resourceable?->team()?->id
                ?? $this->resourceable?->team_id
                ?? $this->resourceable?->environment?->project?->team_id;
        }

        return null;
    }
}
