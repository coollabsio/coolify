<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    public Application $application;

    #[Validate(['boolean'])]
    public bool $isForceHttpsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitSubmodulesEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitLfsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isPreviewDeploymentsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isAutoDeployEnabled = true;

    #[Validate(['boolean'])]
    public bool $disableBuildCache = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGpuEnabled = false;

    #[Validate(['string'])]
    public string $gpuDriver = '';

    #[Validate(['string', 'nullable'])]
    public ?string $gpuCount = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuDeviceIds = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuOptions = null;

    #[Validate(['boolean'])]
    public bool $isBuildServerEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConsistentContainerNameEnabled = false;

    #[Validate(['string', 'nullable'])]
    public ?string $customInternalName = null;

    #[Validate(['boolean'])]
    public bool $isGzipEnabled = true;

    #[Validate(['boolean'])]
    public bool $isStripprefixEnabled = true;

    #[Validate(['boolean'])]
    public bool $isRawComposeDeploymentEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConnectToDockerNetworkEnabled = false;

    public function mount()
    {
        try {
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->application->settings->is_force_https_enabled = $this->isForceHttpsEnabled;
            $this->application->settings->is_git_submodules_enabled = $this->isGitSubmodulesEnabled;
            $this->application->settings->is_git_lfs_enabled = $this->isGitLfsEnabled;
            $this->application->settings->is_preview_deployments_enabled = $this->isPreviewDeploymentsEnabled;
            $this->application->settings->is_auto_deploy_enabled = $this->isAutoDeployEnabled;
            $this->application->settings->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->settings->is_gpu_enabled = $this->isGpuEnabled;
            $this->application->settings->gpu_driver = $this->gpuDriver;
            $this->application->settings->gpu_count = $this->gpuCount;
            $this->application->settings->gpu_device_ids = $this->gpuDeviceIds;
            $this->application->settings->gpu_options = $this->gpuOptions;
            $this->application->settings->is_build_server_enabled = $this->isBuildServerEnabled;
            $this->application->settings->is_consistent_container_name_enabled = $this->isConsistentContainerNameEnabled;
            $this->application->settings->custom_internal_name = $this->customInternalName;
            $this->application->settings->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->settings->is_stripprefix_enabled = $this->isStripprefixEnabled;
            $this->application->settings->is_raw_compose_deployment_enabled = $this->isRawComposeDeploymentEnabled;
            $this->application->settings->connect_to_docker_network = $this->isConnectToDockerNetworkEnabled;
            $this->application->settings->disable_build_cache = $this->disableBuildCache;
            $this->application->settings->save();
        } else {
            $this->isForceHttpsEnabled = $this->application->isForceHttpsEnabled();
            $this->isGzipEnabled = $this->application->isGzipEnabled();
            $this->isStripprefixEnabled = $this->application->isStripprefixEnabled();
            $this->isLogDrainEnabled = $this->application->isLogDrainEnabled();

            $this->isGitSubmodulesEnabled = $this->application->settings->is_git_submodules_enabled;
            $this->isGitLfsEnabled = $this->application->settings->is_git_lfs_enabled;
            $this->isPreviewDeploymentsEnabled = $this->application->settings->is_preview_deployments_enabled;
            $this->isAutoDeployEnabled = $this->application->settings->is_auto_deploy_enabled;
            $this->isGpuEnabled = $this->application->settings->is_gpu_enabled;
            $this->gpuDriver = $this->application->settings->gpu_driver;
            $this->gpuCount = $this->application->settings->gpu_count;
            $this->gpuDeviceIds = $this->application->settings->gpu_device_ids;
            $this->gpuOptions = $this->application->settings->gpu_options;
            $this->isBuildServerEnabled = $this->application->settings->is_build_server_enabled;
            $this->isConsistentContainerNameEnabled = $this->application->settings->is_consistent_container_name_enabled;
            $this->customInternalName = $this->application->settings->custom_internal_name;
            $this->isRawComposeDeploymentEnabled = $this->application->settings->is_raw_compose_deployment_enabled;
            $this->isConnectToDockerNetworkEnabled = $this->application->settings->connect_to_docker_network;
            $this->disableBuildCache = $this->application->settings->disable_build_cache;
        }
    }

    public function instantSave()
    {
        try {
            if ($this->isLogDrainEnabled) {
                if (! $this->application->destination->server->isLogDrainEnabled()) {
                    $this->isLogDrainEnabled = false;
                    $this->syncData(true);
                    $this->dispatch('error', 'Log drain is not enabled on this server.');

                    return;
                }
            }
            if ($this->application->isForceHttpsEnabled() !== $this->isForceHttpsEnabled ||
                $this->application->isGzipEnabled() !== $this->isGzipEnabled ||
                $this->application->isStripprefixEnabled() !== $this->isStripprefixEnabled
            ) {
                $this->dispatch('resetDefaultLabels', false);
            }

            if ($this->application->settings->is_raw_compose_deployment_enabled) {
                $this->application->oldRawParser();
            } else {
                $this->application->parse();
            }
            $this->syncData(true);
            $this->dispatch('success', 'Settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if ($this->gpuCount && $this->gpuDeviceIds) {
                $this->dispatch('error', 'You cannot set both GPU count and GPU device IDs.');
                $this->gpuCount = null;
                $this->gpuDeviceIds = null;
                $this->syncData(true);

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveCustomName()
    {
        if (str($this->customInternalName)->isNotEmpty()) {
            $this->customInternalName = str($this->customInternalName)->slug()->value();
        } else {
            $this->customInternalName = null;
        }
        if (is_null($this->customInternalName)) {
            $this->syncData(true);
            $this->dispatch('success', 'Custom name saved.');

            return;
        }
        $customInternalName = $this->customInternalName;
        $server = $this->application->destination->server;
        $allApplications = $server->applications();

        $foundSameInternalName = $allApplications->filter(function ($application) {
            return $application->id !== $this->application->id && $application->settings->custom_internal_name === $this->customInternalName;
        });
        if ($foundSameInternalName->isNotEmpty()) {
            $this->dispatch('error', 'This custom container name is already in use by another application on this server.');
            $this->customInternalName = $customInternalName;
            $this->syncData(true);

            return;
        }
        $this->syncData(true);
        $this->dispatch('success', 'Custom name saved.');
    }

    public function render()
    {
        return view('livewire.project.application.advanced');
    }
}
