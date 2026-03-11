<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Instance Settings',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The instance settings identifier in the database.'],
        'fqdn' => ['type' => 'string', 'description' => 'The fully qualified domain name of the instance.'],
        'resale_license' => ['type' => 'string', 'description' => 'The resale license of the instance.'],
        'is_registration_enabled' => ['type' => 'boolean', 'description' => 'Whether general registration is enabled.'],
        'is_oauth_registration_enabled' => ['type' => 'boolean', 'description' => 'Whether registration via OAuth is enabled even when general registration is disabled.'],
        'is_auto_update_enabled' => ['type' => 'boolean', 'description' => 'Whether auto update is enabled.'],
        'is_backup_enabled' => ['type' => 'boolean', 'description' => 'Whether backup is enabled.'],
        'is_analytics_enabled' => ['type' => 'boolean', 'description' => 'Whether analytics is enabled.'],
    ]
)]
class InstanceSettings extends Model
{
    protected $table = 'instance_settings';

    protected $guarded = [];

    protected $casts = [
        'is_registration_enabled' => 'boolean',
        'is_oauth_registration_enabled' => 'boolean',
        'is_auto_update_enabled' => 'boolean',
        'is_backup_enabled' => 'boolean',
        'is_analytics_enabled' => 'boolean',
        'is_dns_validation_enabled' => 'boolean',
        'is_api_enabled' => 'boolean',
    ];

    public static function get()
    {
        return self::findOrFail(0);
    }
}
