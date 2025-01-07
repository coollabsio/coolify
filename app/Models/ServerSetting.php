<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        'is_sentinel_enabled' => ['type' => 'boolean'],
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
        'sentinel_metrics_history_days' => ['type' => 'integer'],
        'sentinel_metrics_refresh_rate_seconds' => ['type' => 'integer'],
        'sentinel_token' => ['type' => 'string'],
        'docker_cleanup_frequency' => ['type' => 'string'],
        'docker_cleanup_threshold' => ['type' => 'integer'],
        'server_id' => ['type' => 'integer'],
        'wildcard_domain' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'delete_unused_volumes' => ['type' => 'boolean', 'description' => 'The flag to indicate if the unused volumes should be deleted.'],
        'delete_unused_networks' => ['type' => 'boolean', 'description' => 'The flag to indicate if the unused networks should be deleted.'],
    ]
)]
class ServerSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'force_docker_cleanup' => 'boolean',
        'docker_cleanup_threshold' => 'integer',
        'sentinel_token' => 'encrypted',
        'is_reachable' => 'boolean',
        'is_usable' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($setting) {
            try {
                if (str($setting->sentinel_token)->isEmpty()) {
                    $setting->generateSentinelToken(save: false, ignoreEvent: true);
                }
                if (str($setting->sentinel_custom_url)->isEmpty()) {
                    $setting->generateSentinelUrl(save: false, ignoreEvent: true);
                }
            } catch (\Throwable $e) {
                Log::error('Error creating server setting: '.$e->getMessage());
            }
        });
        static::updated(function ($settings) {
            if (
                $settings->isDirty('sentinel_token') ||
                $settings->isDirty('sentinel_custom_url') ||
                $settings->isDirty('sentinel_metrics_refresh_rate_seconds') ||
                $settings->isDirty('sentinel_metrics_history_days') ||
                $settings->isDirty('sentinel_push_interval_seconds')
            ) {
                $settings->server->restartSentinel();
            }
        });
    }

    public function generateSentinelToken(bool $save = true, bool $ignoreEvent = false)
    {
        $data = [
            'server_uuid' => $this->server->uuid,
        ];
        $token = json_encode($data);
        $encrypted = encrypt($token);
        $this->sentinel_token = $encrypted;
        if ($save) {
            if ($ignoreEvent) {
                $this->saveQuietly();
            } else {
                $this->save();
            }
        }

        return $token;
    }

    public function generateSentinelUrl(bool $save = true, bool $ignoreEvent = false)
    {
        $domain = null;
        $settings = InstanceSettings::get();
        if ($this->server->isLocalhost()) {
            $domain = 'http://host.docker.internal:8000';
        } elseif ($settings->fqdn) {
            $domain = $settings->fqdn;
        } elseif ($settings->public_ipv4) {
            $domain = 'http://'.$settings->public_ipv4.':8000';
        } elseif ($settings->public_ipv6) {
            $domain = 'http://'.$settings->public_ipv6.':8000';
        }
        $this->sentinel_custom_url = $domain;
        if ($save) {
            if ($ignoreEvent) {
                $this->saveQuietly();
            } else {
                $this->save();
            }
        }

        return $domain;
    }

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
