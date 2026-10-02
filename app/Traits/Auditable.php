<?php

namespace App\Traits;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

        auditLog("{$source}.{$resourceType}.{$action}", [
            'team_id' => $teamId,
            "{$resourceType}_uuid" => $this->getAttribute('uuid'),
            "{$resourceType}_name" => $this->getAttribute('name') ?? $this->getAttribute('key'),
            'changed_fields' => $changedFields,
        ]);
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
