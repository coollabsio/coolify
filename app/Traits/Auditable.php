<?php

namespace App\Traits;

use App\Models\AuditEvent;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait Auditable
{
    /**
     * Fields that status checks, Sentinel pushes and server checks write as a side effect.
     * They can change inside a user's request (for example GetContainersStatus::run()),
     * so they must not appear as changes made by that user.
     */
    private const AUDIT_IGNORED_FIELDS = [
        'updated_at',
        'order',
        // Container status and restart tracking (GetContainersStatus, PushServerUpdateJob).
        'status',
        'last_online_at',
        'restart_count',
        'last_restart_at',
        'last_restart_type',
        'restart_limit_reached',
        'container_present',
        'started_at',
        'config_hash',
        'custom_healthcheck_found',
        'domain_dns_statuses',
        // Server heartbeat and check state (Sentinel push, ServerConnectionCheckJob, validation, proxy checks).
        'sentinel_updated_at',
        'sentinel_waiting_since',
        'unreachable_count',
        'high_disk_usage_notification_sent',
        'log_drain_notification_sent',
        'validation_logs',
        'is_validating',
        'detected_traefik_version',
        'traefik_outdated_info',
        'hetzner_server_status',
        'vultr_instance_status',
        'digitalocean_droplet_status',
    ];

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
                    ...self::AUDIT_IGNORED_FIELDS,
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
        try {
            return collect($fields)
                ->reject(fn (string $field): bool => $this->isExcludedAuditField($field))
                ->mapWithKeys(fn (string $field): array => [
                    $field => [
                        'old' => $action === 'created' ? null : AuditEvent::redact($this->getOriginal($field)),
                        'new' => $action === 'deleted' ? null : AuditEvent::redact($this->getAttribute($field)),
                    ],
                ])
                ->all();
        } catch (Throwable $exception) {
            Log::warning('Audit change preparation failed', [
                'resource_type' => Str::snake(class_basename($this)),
                'action' => $action,
                'exception' => $exception::class,
            ]);

            return [];
        }
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
