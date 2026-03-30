<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $casts = [
        'is_static' => 'boolean',
        'is_spa' => 'boolean',
        'is_build_server_enabled' => 'boolean',
        'is_preserve_repository_enabled' => 'boolean',
        'is_container_label_escape_enabled' => 'boolean',
        'is_container_label_readonly_enabled' => 'boolean',
        'use_build_secrets' => 'boolean',
        'inject_build_args_to_dockerfile' => 'boolean',
        'include_source_commit_in_build' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_pr_deployments_public_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
        'is_git_shallow_clone_enabled' => 'boolean',
        'docker_images_to_keep' => 'integer',
    ];

    protected $fillable = [
        'is_static',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_log_drain_enabled',
        'is_gpu_enabled',
        'gpu_driver',
        'gpu_count',
        'gpu_device_ids',
        'gpu_options',
        'is_include_timestamps',
        'is_swarm_only_worker_nodes',
        'is_raw_compose_deployment_enabled',
        'is_build_server_enabled',
        'is_consistent_container_name_enabled',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'connect_to_docker_network',
        'custom_internal_name',
        'is_container_label_escape_enabled',
        'is_env_sorting_enabled',
        'is_container_label_readonly_enabled',
        'is_preserve_repository_enabled',
        'disable_build_cache',
        'is_spa',
        'is_git_shallow_clone_enabled',
        'is_pr_deployments_public_enabled',
        'use_build_secrets',
        'inject_build_args_to_dockerfile',
        'include_source_commit_in_build',
        'docker_images_to_keep',
    ];

    public function isStatic(): Attribute
    {
        return Attribute::make(
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
