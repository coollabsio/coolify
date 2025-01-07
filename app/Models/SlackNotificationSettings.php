<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class SlackNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'slack_enabled',
        'slack_webhook_url',

        'deployment_success_slack_notifications',
        'deployment_failure_slack_notifications',
        'status_change_slack_notifications',
        'backup_success_slack_notifications',
        'backup_failure_slack_notifications',
        'scheduled_task_success_slack_notifications',
        'scheduled_task_failure_slack_notifications',
        'docker_cleanup_slack_notifications',
        'server_disk_usage_slack_notifications',
        'server_reachable_slack_notifications',
        'server_unreachable_slack_notifications',
    ];

    protected $casts = [
        'slack_enabled' => 'boolean',
        'slack_webhook_url' => 'encrypted',

        'deployment_success_slack_notifications' => 'boolean',
        'deployment_failure_slack_notifications' => 'boolean',
        'status_change_slack_notifications' => 'boolean',
        'backup_success_slack_notifications' => 'boolean',
        'backup_failure_slack_notifications' => 'boolean',
        'scheduled_task_success_slack_notifications' => 'boolean',
        'scheduled_task_failure_slack_notifications' => 'boolean',
        'docker_cleanup_slack_notifications' => 'boolean',
        'server_disk_usage_slack_notifications' => 'boolean',
        'server_reachable_slack_notifications' => 'boolean',
        'server_unreachable_slack_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->slack_enabled;
    }
}
