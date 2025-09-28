<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class MatrixNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'matrix_enabled',
        'matrix_homeserver_url',
        'matrix_room_id',
        'matrix_access_token',
        'matrix_friendly_name',

        'deployment_success_matrix_notifications',
        'deployment_failure_matrix_notifications',
        'status_change_matrix_notifications',
        'backup_success_matrix_notifications',
        'backup_failure_matrix_notifications',
        'scheduled_task_success_matrix_notifications',
        'scheduled_task_failure_matrix_notifications',
        'docker_cleanup_success_matrix_notifications',
        'docker_cleanup_failure_matrix_notifications',
        'server_disk_usage_matrix_notifications',
        'server_reachable_matrix_notifications',
        'server_unreachable_matrix_notifications',
        'server_patch_matrix_notifications',
    ];

    protected $casts = [
        'matrix_enabled' => 'boolean',
        'matrix_homeserver_url' => 'encrypted',
        'matrix_room_id' => 'encrypted',
        'matrix_access_token' => 'encrypted',

        'deployment_success_matrix_notifications' => 'boolean',
        'deployment_failure_matrix_notifications' => 'boolean',
        'status_change_matrix_notifications' => 'boolean',
        'backup_success_matrix_notifications' => 'boolean',
        'backup_failure_matrix_notifications' => 'boolean',
        'scheduled_task_success_matrix_notifications' => 'boolean',
        'scheduled_task_failure_matrix_notifications' => 'boolean',
        'docker_cleanup_success_matrix_notifications' => 'boolean',
        'docker_cleanup_failure_matrix_notifications' => 'boolean',
        'server_disk_usage_matrix_notifications' => 'boolean',
        'server_reachable_matrix_notifications' => 'boolean',
        'server_unreachable_matrix_notifications' => 'boolean',
        'server_patch_matrix_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->matrix_enabled;
    }
}