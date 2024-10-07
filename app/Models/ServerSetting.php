<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Server Settings model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'concurrent_builds' => ['type' => 'integer'],
        'dynamic_timeout' => ['type' => 'integer'],
        'force_disabled' => ['type' => 'boolean'],
        'force_server_cleanup' => ['type' => 'boolean'],
        'is_build_server' => ['type' => 'boolean'],
        'is_cloudflare_tunnel' => ['type' => 'boolean'],
        'is_jump_server' => ['type' => 'boolean'],
        'is_logdrain_axiom_enabled' => ['type' => 'boolean'],
        'is_logdrain_custom_enabled' => ['type' => 'boolean'],
        'is_logdrain_highlight_enabled' => ['type' => 'boolean'],
        'is_logdrain_newrelic_enabled' => ['type' => 'boolean'],
        'is_metrics_enabled' => ['type' => 'boolean'],
        'is_reachable' => ['type' => 'boolean'],
        'is_server_api_enabled' => ['type' => 'boolean'],
        'is_swarm_manager' => ['type' => 'boolean'],
        'is_swarm_worker' => ['type' => 'boolean'],
        'is_usable' => ['type' => 'boolean'],
        'logdrain_axiom_api_key' => ['type' => 'string'],
        'logdrain_axiom_dataset_name' => ['type' => 'string'],
        'logdrain_custom_config' => ['type' => 'string'],
        'logdrain_custom_config_parser' => ['type' => 'string'],
        'logdrain_highlight_project_id' => ['type' => 'string'],
        'logdrain_newrelic_base_uri' => ['type' => 'string'],
        'logdrain_newrelic_license_key' => ['type' => 'string'],
        'metrics_history_days' => ['type' => 'integer'],
        'metrics_refresh_rate_seconds' => ['type' => 'integer'],
        'metrics_token' => ['type' => 'string'],
        'docker_cleanup_frequency' => ['type' => 'string'],
        'docker_cleanup_threshold' => ['type' => 'integer'],
        'server_id' => ['type' => 'integer'],
        'wildcard_domain' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ]
)]
class ServerSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'force_docker_cleanup' => 'boolean',
        'docker_cleanup_threshold' => 'integer',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function dockerCleanupFrequency(): Attribute
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
}
