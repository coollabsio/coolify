<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class TelegramNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'telegram_enabled',
        'telegram_token',
        'telegram_chat_id',

        'deployment_success_telegram_notifications',
        'deployment_failure_telegram_notifications',
        'status_change_telegram_notifications',
        'backup_success_telegram_notifications',
        'backup_failure_telegram_notifications',
        'scheduled_task_success_telegram_notifications',
        'scheduled_task_failure_telegram_notifications',
        'docker_cleanup_telegram_notifications',
        'server_disk_usage_telegram_notifications',
        'server_reachable_telegram_notifications',
        'server_unreachable_telegram_notifications',

        'telegram_notifications_deployment_success_thread_id',
        'telegram_notifications_deployment_failure_thread_id',
        'telegram_notifications_status_change_thread_id',
        'telegram_notifications_backup_success_thread_id',
        'telegram_notifications_backup_failure_thread_id',
        'telegram_notifications_scheduled_task_success_thread_id',
        'telegram_notifications_scheduled_task_failure_thread_id',
        'telegram_notifications_docker_cleanup_thread_id',
        'telegram_notifications_server_disk_usage_thread_id',
        'telegram_notifications_server_reachable_thread_id',
        'telegram_notifications_server_unreachable_thread_id',
    ];

    protected $casts = [
        'telegram_enabled' => 'boolean',
        'telegram_token' => 'encrypted',
        'telegram_chat_id' => 'encrypted',

        'deployment_success_telegram_notifications' => 'boolean',
        'deployment_failure_telegram_notifications' => 'boolean',
        'status_change_telegram_notifications' => 'boolean',
        'backup_success_telegram_notifications' => 'boolean',
        'backup_failure_telegram_notifications' => 'boolean',
        'scheduled_task_success_telegram_notifications' => 'boolean',
        'scheduled_task_failure_telegram_notifications' => 'boolean',
        'docker_cleanup_telegram_notifications' => 'boolean',
        'server_disk_usage_telegram_notifications' => 'boolean',
        'server_reachable_telegram_notifications' => 'boolean',
        'server_unreachable_telegram_notifications' => 'boolean',

        'telegram_notifications_deployment_success_thread_id' => 'encrypted',
        'telegram_notifications_deployment_failure_thread_id' => 'encrypted',
        'telegram_notifications_status_change_thread_id' => 'encrypted',
        'telegram_notifications_backup_success_thread_id' => 'encrypted',
        'telegram_notifications_backup_failure_thread_id' => 'encrypted',
        'telegram_notifications_scheduled_task_success_thread_id' => 'encrypted',
        'telegram_notifications_scheduled_task_failure_thread_id' => 'encrypted',
        'telegram_notifications_docker_cleanup_thread_id' => 'encrypted',
        'telegram_notifications_server_disk_usage_thread_id' => 'encrypted',
        'telegram_notifications_server_reachable_thread_id' => 'encrypted',
        'telegram_notifications_server_unreachable_thread_id' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->telegram_enabled;
    }
}
