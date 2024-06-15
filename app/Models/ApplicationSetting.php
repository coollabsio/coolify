<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $is_static
 * @property bool $is_git_submodules_enabled
 * @property bool $is_git_lfs_enabled
 * @property bool $is_auto_deploy_enabled
 * @property bool $is_force_https_enabled
 * @property bool $is_debug_enabled
 * @property bool $is_preview_deployments_enabled
 * @property int $application_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_log_drain_enabled
 * @property bool $is_gpu_enabled
 * @property string $gpu_driver
 * @property string|null $gpu_count
 * @property string|null $gpu_device_ids
 * @property string|null $gpu_options
 * @property bool $is_include_timestamps
 * @property bool $is_swarm_only_worker_nodes
 * @property bool $is_raw_compose_deployment_enabled
 * @property bool $is_build_server_enabled
 * @property bool $is_consistent_container_name_enabled
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property bool $connect_to_docker_network
 * @property string|null $custom_internal_name
 * @property bool $is_container_label_escape_enabled
 * @property bool $is_env_sorting_enabled
 * @property-read \App\Models\Application|null $application
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereConnectToDockerNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereCustomInternalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereGpuCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereGpuDeviceIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereGpuDriver($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereGpuOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsAutoDeployEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsBuildServerEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsConsistentContainerNameEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsContainerLabelEscapeEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsDebugEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsEnvSortingEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsForceHttpsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsGitLfsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsGitSubmodulesEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsGpuEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsGzipEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsPreviewDeploymentsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsRawComposeDeploymentEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsStatic($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsStripprefixEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereIsSwarmOnlyWorkerNodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ApplicationSetting extends Model
{
    protected $cast = [
        'is_static' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
    ];

    protected $guarded = [];

    public function isStatic(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return $value;
            },
            set: function ($value) {
                if ($value) {
                    $this->application->ports_exposes = 80;
                }
                $this->application->save();

                return $value;
            }
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
