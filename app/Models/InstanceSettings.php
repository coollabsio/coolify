<?php

namespace App\Models;

use App\Jobs\PullHelperImageJob;
use App\Notifications\Channels\SendsEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\Url\Url;

class InstanceSettings extends Model implements SendsEmail
{
    use Notifiable;

    protected $guarded = [];

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

        'allowed_ip_ranges' => 'array',
        'is_auto_update_enabled' => 'boolean',
        'auto_update_frequency' => 'string',
        'update_check_frequency' => 'string',
        'sentinel_token' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::updated(function ($settings) {
            if ($settings->isDirty('helper_version')) {
                Server::chunkById(100, function ($servers) {
                    foreach ($servers as $server) {
                        PullHelperImageJob::dispatch($server);
                    }
                });
            }
        });
    }

    public function fqdn(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $url = Url::fromString($value);
                    $host = $url->getHost();

                    return $url->getScheme().'://'.$host;
                }
            }
        );
    }

    public function updateCheckFrequency(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                return translate_cron_expression($value);
            },
            get: function ($value) {
                return translate_cron_expression($value);
            }
        );
    }

    public function autoUpdateFrequency(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                return translate_cron_expression($value);
            },
            get: function ($value) {
                return translate_cron_expression($value);
            }
        );
    }

    public static function get()
    {
        return InstanceSettings::findOrFail(0);
    }

    public function getRecipients($notification)
    {
        $recipients = data_get($notification, 'emails', null);
        if (is_null($recipients) || $recipients === '') {
            return [];
        }

        return explode(',', $recipients);
    }

    public function getTitleDisplayName(): string
    {
        $instanceName = $this->instance_name;
        if (! $instanceName) {
            return '';
        }

        return "[{$instanceName}]";
    }

    // public function helperVersion(): Attribute
    // {
    //     return Attribute::make(
    //         get: function ($value) {
    //             if (isDev()) {
    //                 return 'latest';
    //             }

    //             return $value;
    //         }
    //     );
    // }
}
