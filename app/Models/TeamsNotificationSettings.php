<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class TeamsNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'teams_enabled',
        'teams_webhook_url',

        'deployment_success_teams_notifications',
        'deployment_failure_teams_notifications',
        'status_change_teams_notifications',
        'backup_success_teams_notifications',
        'backup_failure_teams_notifications',
        'scheduled_task_success_teams_notifications',
        'scheduled_task_failure_teams_notifications',
        'docker_cleanup_success_teams_notifications',
        'docker_cleanup_failure_teams_notifications',
        'server_disk_usage_teams_notifications',
        'server_reachable_teams_notifications',
        'server_unreachable_teams_notifications',
    ];

    protected $casts = [
        'teams_enabled' => 'boolean',
        'teams_webhook_url' => 'encrypted',

        'deployment_success_teams_notifications' => 'boolean',
        'deployment_failure_teams_notifications' => 'boolean',
        'status_change_teams_notifications' => 'boolean',
        'backup_success_teams_notifications' => 'boolean',
        'backup_failure_teams_notifications' => 'boolean',
        'scheduled_task_success_teams_notifications' => 'boolean',
        'scheduled_task_failure_teams_notifications' => 'boolean',
        'docker_cleanup_success_teams_notifications' => 'boolean',
        'docker_cleanup_failure_teams_notifications' => 'boolean',
        'server_disk_usage_teams_notifications' => 'boolean',
        'server_reachable_teams_notifications' => 'boolean',
        'server_unreachable_teams_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->teams_enabled;
    }
}
