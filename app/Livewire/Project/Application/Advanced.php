<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Advanced extends Component
{
    public Application $application;

    public bool $is_force_https_enabled;

    public bool $is_gzip_enabled;

    public bool $is_stripprefix_enabled;

    protected $rules = [
        'application.settings.is_git_submodules_enabled' => 'boolean|required',
        'application.settings.is_git_lfs_enabled' => 'boolean|required',
        'application.settings.is_preview_deployments_enabled' => 'boolean|required',
        'application.settings.is_auto_deploy_enabled' => 'boolean|required',
        'is_force_https_enabled' => 'boolean|required',
        'application.settings.is_log_drain_enabled' => 'boolean|required',
        'application.settings.is_gpu_enabled' => 'boolean|required',
        'application.settings.is_build_server_enabled' => 'boolean|required',
        'application.settings.is_consistent_container_name_enabled' => 'boolean|required',
        'application.settings.custom_internal_name' => 'string|nullable',
        'application.settings.is_gzip_enabled' => 'boolean|required',
        'application.settings.is_stripprefix_enabled' => 'boolean|required',
        'application.settings.gpu_driver' => 'string|required',
        'application.settings.gpu_count' => 'string|required',
        'application.settings.gpu_device_ids' => 'string|required',
        'application.settings.gpu_options' => 'string|required',
        'application.settings.is_raw_compose_deployment_enabled' => 'boolean|required',
        'application.settings.connect_to_docker_network' => 'boolean|required',
    ];

    public function mount()
    {
        $this->is_force_https_enabled = $this->application->isForceHttpsEnabled();
        $this->is_gzip_enabled = $this->application->isGzipEnabled();
        $this->is_stripprefix_enabled = $this->application->isStripprefixEnabled();
    }

    public function instantSave()
    {
        if ($this->application->isLogDrainEnabled()) {
            if (! $this->application->destination->server->isLogDrainEnabled()) {
                $this->application->settings->is_log_drain_enabled = false;
                $this->dispatch('error', 'Log drain is not enabled on this server.');

                return;
            }
        }
        if ($this->application->settings->is_force_https_enabled !== $this->is_force_https_enabled) {
            $this->application->settings->is_force_https_enabled = $this->is_force_https_enabled;
            $this->dispatch('resetDefaultLabels', false);
        }
        if ($this->application->settings->is_gzip_enabled !== $this->is_gzip_enabled) {
            $this->application->settings->is_gzip_enabled = $this->is_gzip_enabled;
            $this->dispatch('resetDefaultLabels', false);
        }
        if ($this->application->settings->is_stripprefix_enabled !== $this->is_stripprefix_enabled) {
            $this->application->settings->is_stripprefix_enabled = $this->is_stripprefix_enabled;
            $this->dispatch('resetDefaultLabels', false);
        }
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            $this->application->parseRawCompose();
        } else {
            $this->application->parseCompose();
        }
        $this->application->settings->save();
        $this->dispatch('success', 'Settings saved.');
        $this->dispatch('configurationChanged');
    }

    public function submit()
    {
        if ($this->application->settings->gpu_count && $this->application->settings->gpu_device_ids) {
            $this->dispatch('error', 'You cannot set both GPU count and GPU device IDs.');
            $this->application->settings->gpu_count = null;
            $this->application->settings->gpu_device_ids = null;
            $this->application->settings->save();

            return;
        }
        $this->application->settings->save();
        $this->dispatch('success', 'Settings saved.');
    }

    public function saveCustomName()
    {
        if (isset($this->application->settings->custom_internal_name)) {
            $this->application->settings->custom_internal_name = str($this->application->settings->custom_internal_name)->slug()->value();
        } else {
            $this->application->settings->custom_internal_name = null;
        }
        $this->application->settings->save();
        $this->dispatch('success', 'Custom name saved.');
    }

    public function render()
    {
        return view('livewire.project.application.advanced');
    }
}
