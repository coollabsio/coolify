<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotificationSettings extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'smtp_enabled',
        'smtp_from_address',
        'smtp_from_name',
        'smtp_recipients',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_timeout',

        'resend_enabled',
        'resend_api_key',

        'use_instance_email_settings',

        'deployment_success_email_notifications',
        'deployment_failure_email_notifications',
        'status_change_email_notifications',
        'backup_success_email_notifications',
        'backup_failure_email_notifications',
        'scheduled_task_success_email_notifications',
        'scheduled_task_failure_email_notifications',
        'server_disk_usage_email_notifications',
    ];

    protected $casts = [
        'smtp_enabled' => 'boolean',
        'smtp_from_address' => 'encrypted',
        'smtp_from_name' => 'encrypted',
        'smtp_recipients' => 'encrypted',
        'smtp_host' => 'encrypted',
        'smtp_port' => 'integer',
        'smtp_username' => 'encrypted',
        'smtp_password' => 'encrypted',
        'smtp_timeout' => 'integer',

        'resend_enabled' => 'boolean',
        'resend_api_key' => 'encrypted',

        'use_instance_email_settings' => 'boolean',

        'deployment_success_email_notifications' => 'boolean',
        'deployment_failure_email_notifications' => 'boolean',
        'status_change_email_notifications' => 'boolean',
        'backup_success_email_notifications' => 'boolean',
        'backup_failure_email_notifications' => 'boolean',
        'scheduled_task_success_email_notifications' => 'boolean',
        'scheduled_task_failure_email_notifications' => 'boolean',
        'server_disk_usage_email_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        if (isCloud()) {
            return true;
        }

        return $this->smtp_enabled || $this->resend_enabled || $this->use_instance_email_settings;
    }
}
