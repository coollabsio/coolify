<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $is_swarm_manager
 * @property bool $is_jump_server
 * @property bool $is_build_server
 * @property bool $is_reachable
 * @property bool $is_usable
 * @property int $server_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $wildcard_domain
 * @property int $cleanup_after_percentage
 * @property bool $is_cloudflare_tunnel
 * @property bool $is_logdrain_newrelic_enabled
 * @property string|null $logdrain_newrelic_license_key
 * @property string|null $logdrain_newrelic_base_uri
 * @property bool $is_logdrain_highlight_enabled
 * @property string|null $logdrain_highlight_project_id
 * @property bool $is_logdrain_axiom_enabled
 * @property string|null $logdrain_axiom_dataset_name
 * @property string|null $logdrain_axiom_api_key
 * @property bool $is_swarm_worker
 * @property bool $is_logdrain_custom_enabled
 * @property string|null $logdrain_custom_config
 * @property string|null $logdrain_custom_config_parser
 * @property int $concurrent_builds
 * @property int $dynamic_timeout
 * @property bool $force_disabled
 * @property-read \App\Models\Server|null $server
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereCleanupAfterPercentage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereConcurrentBuilds($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereDynamicTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereForceDisabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsBuildServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsCloudflareTunnel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsJumpServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsLogdrainAxiomEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsLogdrainCustomEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsLogdrainHighlightEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsLogdrainNewrelicEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsReachable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsSwarmManager($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsSwarmWorker($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereIsUsable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainAxiomApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainAxiomDatasetName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainCustomConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainCustomConfigParser($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainHighlightProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainNewrelicBaseUri($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereLogdrainNewrelicLicenseKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ServerSetting whereWildcardDomain($value)
 *
 * @mixin \Eloquent
 */
class ServerSetting extends Model
{
    protected $guarded = [];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
