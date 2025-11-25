<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class WebhookNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'webhook_enabled',
        'webhook_url',

        'deployment_success_webhook_notifications',
        'deployment_failure_webhook_notifications',
        'status_change_webhook_notifications',
        'backup_success_webhook_notifications',
        'backup_failure_webhook_notifications',
        'scheduled_task_success_webhook_notifications',
        'scheduled_task_failure_webhook_notifications',
        'docker_cleanup_success_webhook_notifications',
        'docker_cleanup_failure_webhook_notifications',
        'server_disk_usage_webhook_notifications',
        'server_reachable_webhook_notifications',
        'server_unreachable_webhook_notifications',
        'server_patch_webhook_notifications',
        'traefik_outdated_webhook_notifications',
    ];

    protected function casts(): array
    {
        return [
            'webhook_enabled' => 'boolean',
            'webhook_url' => 'encrypted',

            'deployment_success_webhook_notifications' => 'boolean',
            'deployment_failure_webhook_notifications' => 'boolean',
            'status_change_webhook_notifications' => 'boolean',
            'backup_success_webhook_notifications' => 'boolean',
            'backup_failure_webhook_notifications' => 'boolean',
            'scheduled_task_success_webhook_notifications' => 'boolean',
            'scheduled_task_failure_webhook_notifications' => 'boolean',
            'docker_cleanup_success_webhook_notifications' => 'boolean',
            'docker_cleanup_failure_webhook_notifications' => 'boolean',
            'server_disk_usage_webhook_notifications' => 'boolean',
            'server_reachable_webhook_notifications' => 'boolean',
            'server_unreachable_webhook_notifications' => 'boolean',
            'server_patch_webhook_notifications' => 'boolean',
            'traefik_outdated_webhook_notifications' => 'boolean',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->webhook_enabled;
    }
}
