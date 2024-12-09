<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class DiscordNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'discord_enabled',
        'discord_webhook_url',

        'deployment_success_discord_notifications',
        'deployment_failure_discord_notifications',
        'status_change_discord_notifications',
        'backup_success_discord_notifications',
        'backup_failure_discord_notifications',
        'scheduled_task_success_discord_notifications',
        'scheduled_task_failure_discord_notifications',
        'docker_cleanup_discord_notifications',
        'server_disk_usage_discord_notifications',
        'server_reachable_discord_notifications',
        'server_unreachable_discord_notifications',
    ];

    protected $casts = [
        'discord_enabled' => 'boolean',
        'discord_webhook_url' => 'encrypted',

        'deployment_success_discord_notifications' => 'boolean',
        'deployment_failure_discord_notifications' => 'boolean',
        'status_change_discord_notifications' => 'boolean',
        'backup_success_discord_notifications' => 'boolean',
        'backup_failure_discord_notifications' => 'boolean',
        'scheduled_task_success_discord_notifications' => 'boolean',
        'scheduled_task_failure_discord_notifications' => 'boolean',
        'docker_cleanup_discord_notifications' => 'boolean',
        'server_disk_usage_discord_notifications' => 'boolean',
        'server_reachable_discord_notifications' => 'boolean',
        'server_unreachable_discord_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->discord_enabled;
    }
}
