<?php

namespace App\Traits;

use App\Models\AuditEvent;
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

        AuditEvent::recordModelMutation("{$source}.{$resourceType}.{$action}", [
            'team_id' => $teamId,
            "{$resourceType}_uuid" => $this->getAttribute('uuid'),
            "{$resourceType}_name" => $this->getAttribute('name') ?? $this->getAttribute('key'),
            'changed_fields' => $changedFields,
        ], $this->auditChanges(
            $action,
            $action === 'updated' ? $changedFields : array_keys($this->getAttributes()),
        ));
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function auditChanges(string $action, array $fields): array
    {
        return collect($fields)
            ->reject(fn (string $field): bool => $this->isExcludedAuditField($field))
            ->mapWithKeys(fn (string $field): array => [
                $field => [
                    'old' => $action === 'created' ? null : AuditEvent::redact($this->getOriginal($field)),
                    'new' => $action === 'deleted' ? null : AuditEvent::redact($this->getAttribute($field)),
                ],
            ])
            ->all();
    }

    private function isExcludedAuditField(string $field): bool
    {
        $cast = $this->getCasts()[$field] ?? null;

        return in_array($field, [
            $this->getKeyName(),
            'uuid',
            'created_at',
            'updated_at',
            'deleted_at',
            'order',
            'status',
            ...$this->getHidden(),
            ...($this->auditExclude ?? []),
        ], true)
            || str_ends_with($field, '_id')
            || str_ends_with($field, '_at')
            || preg_match('/password|secret|token|private_key|signature|credential|api_key|access_key|authorization|cookie/i', $field)
            || (is_string($cast) && ($cast === 'encrypted' || str_starts_with($cast, 'encrypted:')));
    }

    public function withoutAuditLogging(Closure $callback): mixed
    {
        $wasAuditLoggingEnabled = $this->auditLoggingEnabled;
        $this->auditLoggingEnabled = false;

        try {
            return $callback();
        } finally {
            $this->auditLoggingEnabled = $wasAuditLoggingEnabled;
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
