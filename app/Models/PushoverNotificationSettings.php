<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class PushoverNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'pushover_enabled',
        'pushover_user_key',
        'pushover_api_token',

        'deployment_success_pushover_notifications',
        'deployment_failure_pushover_notifications',
        'status_change_pushover_notifications',
        'backup_success_pushover_notifications',
        'backup_failure_pushover_notifications',
        'scheduled_task_success_pushover_notifications',
        'scheduled_task_failure_pushover_notifications',
        'docker_cleanup_pushover_notifications',
        'server_disk_usage_pushover_notifications',
        'server_reachable_pushover_notifications',
        'server_unreachable_pushover_notifications',
    ];

    protected $casts = [
        'pushover_enabled' => 'boolean',
        'pushover_user_key' => 'encrypted',
        'pushover_api_token' => 'encrypted',

        'deployment_success_pushover_notifications' => 'boolean',
        'deployment_failure_pushover_notifications' => 'boolean',
        'status_change_pushover_notifications' => 'boolean',
        'backup_success_pushover_notifications' => 'boolean',
        'backup_failure_pushover_notifications' => 'boolean',
        'scheduled_task_success_pushover_notifications' => 'boolean',
        'scheduled_task_failure_pushover_notifications' => 'boolean',
        'docker_cleanup_pushover_notifications' => 'boolean',
        'server_disk_usage_pushover_notifications' => 'boolean',
        'server_reachable_pushover_notifications' => 'boolean',
        'server_unreachable_pushover_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->pushover_enabled;
    }
}
