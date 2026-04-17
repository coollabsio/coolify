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
                'is_oauth_registration_enabled',
                'force_oauth_only_login',
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
            ];

    protected $casts = [
                'do_not_track' => 'boolean',
                'is_auto_update_enabled' => 'boolean',
                'is_registration_enabled' => 'boolean',
                'is_oauth_registration_enabled' => 'boolean',
                'force_oauth_only_login' => 'boolean',
                'smtp_enabled' => 'boolean',
                'resend_enabled' => 'boolean',
            ];
    }
