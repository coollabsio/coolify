<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class MicrosoftTeamsNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'microsoft_teams_enabled',
        'microsoft_teams_webhook_url',

        'deployment_success_microsoft_teams_notifications',
        'deployment_failure_microsoft_teams_notifications',
        'status_change_microsoft_teams_notifications',
        'restart_limit_reached_microsoft_teams_notifications',
        'backup_success_microsoft_teams_notifications',
        'backup_failure_microsoft_teams_notifications',
        'scheduled_task_success_microsoft_teams_notifications',
        'scheduled_task_failure_microsoft_teams_notifications',
        'docker_cleanup_success_microsoft_teams_notifications',
        'docker_cleanup_failure_microsoft_teams_notifications',
        'server_disk_usage_microsoft_teams_notifications',
        'server_reachable_microsoft_teams_notifications',
        'server_unreachable_microsoft_teams_notifications',
        'server_patch_microsoft_teams_notifications',
        'traefik_outdated_microsoft_teams_notifications',
    ];

    protected $hidden = [
        'microsoft_teams_webhook_url',
    ];

    protected $casts = [
        'microsoft_teams_enabled' => 'boolean',
        'microsoft_teams_webhook_url' => 'encrypted',

        'deployment_success_microsoft_teams_notifications' => 'boolean',
        'deployment_failure_microsoft_teams_notifications' => 'boolean',
        'status_change_microsoft_teams_notifications' => 'boolean',
        'restart_limit_reached_microsoft_teams_notifications' => 'boolean',
        'backup_success_microsoft_teams_notifications' => 'boolean',
        'backup_failure_microsoft_teams_notifications' => 'boolean',
        'scheduled_task_success_microsoft_teams_notifications' => 'boolean',
        'scheduled_task_failure_microsoft_teams_notifications' => 'boolean',
        'docker_cleanup_success_microsoft_teams_notifications' => 'boolean',
        'docker_cleanup_failure_microsoft_teams_notifications' => 'boolean',
        'server_disk_usage_microsoft_teams_notifications' => 'boolean',
        'server_reachable_microsoft_teams_notifications' => 'boolean',
        'server_unreachable_microsoft_teams_notifications' => 'boolean',
        'server_patch_microsoft_teams_notifications' => 'boolean',
        'traefik_outdated_microsoft_teams_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->microsoft_teams_enabled;
    }
}
