<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class GotifyNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'gotify_enabled',
        'gotify_url',
        'gotify_token',

        'deployment_success_gotify_notifications',
        'deployment_failure_gotify_notifications',
        'status_change_gotify_notifications',
        'backup_success_gotify_notifications',
        'backup_failure_gotify_notifications',
        'scheduled_task_success_gotify_notifications',
        'scheduled_task_failure_gotify_notifications',
        'docker_cleanup_gotify_notifications',
        'server_disk_usage_gotify_notifications',
        'server_reachable_gotify_notifications',
        'server_unreachable_gotify_notifications',
        'server_patch_gotify_notifications',
    ];

    protected $casts = [
        'gotify_enabled' => 'boolean',
        'gotify_url' => 'encrypted',
        'gotify_token' => 'encrypted',

        'deployment_success_gotify_notifications' => 'boolean',
        'deployment_failure_gotify_notifications' => 'boolean',
        'status_change_gotify_notifications' => 'boolean',
        'backup_success_gotify_notifications' => 'boolean',
        'backup_failure_gotify_notifications' => 'boolean',
        'scheduled_task_success_gotify_notifications' => 'boolean',
        'scheduled_task_failure_gotify_notifications' => 'boolean',
        'docker_cleanup_gotify_notifications' => 'boolean',
        'server_disk_usage_gotify_notifications' => 'boolean',
        'server_reachable_gotify_notifications' => 'boolean',
        'server_unreachable_gotify_notifications' => 'boolean',
        'server_patch_gotify_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->gotify_enabled;
    }
}
