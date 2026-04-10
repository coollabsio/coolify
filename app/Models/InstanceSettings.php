<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;
use Spatie\Url\Url;

class InstanceSettings extends Model
{
    protected $fillable = [
        'public_ipv4',
        'public_ipv6',
        'fqdn',
        'public_port_min',
        'public_port_max',
        'do_not_track',
        'is_auto_update_enabled',
        'is_registration_enabled',
        'next_channel',
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
        'is_dns_validation_enabled',
        'custom_dns_servers',
        'instance_name',
        'is_api_enabled',
        'allowed_ips',
        'auto_update_frequency',
        'update_check_frequency',
        'new_version_available',
        'instance_timezone',
        'helper_version',
        'disable_two_step_confirmation',
        'is_sponsorship_popup_enabled',
        'dev_helper_version',
        'is_wire_navigate_enabled',
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

        'allowed_ip_ranges' => 'array',
        'is_auto_update_enabled' => 'boolean',
        'auto_update_frequency' => 'string',
        'update_check_frequency' => 'string',
        'sentinel_token' => 'encrypted',
        'is_wire_navigate_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updated(function ($settings) {
            // Clear once() cache so subsequent calls get fresh data
            Once::flush();

            // Clear trusted hosts cache when FQDN changes
            if ($settings->wasChanged('fqdn')) {
                \Cache::forget('instance_settings_fqdn_host');
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
        return once(fn () => InstanceSettings::findOrFail(0));
    }

    // public function getRecipients($notification)
    // {
    //     $recipients = data_get($notification, 'emails', null);
    //     if (is_null($recipients) || $recipients === '') {
    //         return [];
    //     }

    //     return explode(',', $recipients);
    // }

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
