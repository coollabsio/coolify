<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class NtfyNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'ntfy_enabled',
        'ntfy_url',
        'ntfy_topic',
        'ntfy_token',
        'ntfy_username',
        'ntfy_password',
        'ntfy_auth_method',

        'ntfy_priority_success_events',
        'ntfy_priority_info_events',
        'ntfy_priority_warning_events',
        'ntfy_priority_error_events',

        'deployment_success_ntfy_notifications',
        'deployment_failure_ntfy_notifications',
        'status_change_ntfy_notifications',
        'backup_success_ntfy_notifications',
        'backup_failure_ntfy_notifications',
        'scheduled_task_success_ntfy_notifications',
        'scheduled_task_failure_ntfy_notifications',
        'docker_cleanup_success_ntfy_notifications',
        'docker_cleanup_failure_ntfy_notifications',
        'server_disk_usage_ntfy_notifications',
        'server_reachable_ntfy_notifications',
        'server_unreachable_ntfy_notifications',
        'server_patch_ntfy_notifications',
        'traefik_outdated_ntfy_notifications',
    ];

    protected function casts(): array
    {
        return [
            'ntfy_enabled' => 'boolean',
            'ntfy_url' => 'encrypted',
            'ntfy_topic' => 'encrypted',
            'ntfy_token' => 'encrypted',
            'ntfy_username' => 'encrypted',
            'ntfy_password' => 'encrypted',

            'ntfy_priority_success_events' => 'integer',
            'ntfy_priority_info_events' => 'integer',
            'ntfy_priority_warning_events' => 'integer',
            'ntfy_priority_error_events' => 'integer',

            'deployment_success_ntfy_notifications' => 'boolean',
            'deployment_failure_ntfy_notifications' => 'boolean',
            'status_change_ntfy_notifications' => 'boolean',
            'backup_success_ntfy_notifications' => 'boolean',
            'backup_failure_ntfy_notifications' => 'boolean',
            'scheduled_task_success_ntfy_notifications' => 'boolean',
            'scheduled_task_failure_ntfy_notifications' => 'boolean',
            'docker_cleanup_success_ntfy_notifications' => 'boolean',
            'docker_cleanup_failure_ntfy_notifications' => 'boolean',
            'server_disk_usage_ntfy_notifications' => 'boolean',
            'server_reachable_ntfy_notifications' => 'boolean',
            'server_unreachable_ntfy_notifications' => 'boolean',
            'server_patch_ntfy_notifications' => 'boolean',
            'traefik_outdated_ntfy_notifications' => 'boolean',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->ntfy_enabled;
    }

    public function getPriorityForLevel(string $level): int
    {
        return match ($level) {
            'success' => $this->ntfy_priority_success_events ?? 2,
            'info' => $this->ntfy_priority_info_events ?? 3,
            'warning' => $this->ntfy_priority_warning_events ?? 4,
            'error' => $this->ntfy_priority_error_events ?? 5,
            default => 3,
        };
    }
}
