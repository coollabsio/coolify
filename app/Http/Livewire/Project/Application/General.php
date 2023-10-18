<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

class General extends Component
{
    public string $applicationId;

    public Application $application;
    public Collection $services;
    public string $name;
    public ?string $fqdn = null;
    public string $git_repository;
    public string $git_branch;
    public ?string $git_commit_sha = null;
    public string $build_pack;

    public $customLabels;
    public bool $labelsChanged = false;

    public bool $is_static;
    public bool $is_git_submodules_enabled;
    public bool $is_git_lfs_enabled;
    public bool $is_debug_enabled;
    public bool $is_preview_deployments_enabled;
    public bool $is_auto_deploy_enabled;
    public bool $is_force_https_enabled;


    protected $rules = [
        'application.name' => 'required',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
        'application.install_command' => 'nullable',
        'application.build_command' => 'nullable',
        'application.start_command' => 'nullable',
        'application.build_pack' => 'required',
        'application.static_image' => 'required',
        'application.base_directory' => 'required',
        'application.publish_directory' => 'nullable',
        'application.ports_exposes' => 'required',
        'application.ports_mappings' => 'nullable',
        'application.dockerfile' => 'nullable',
        'application.docker_registry_image_name' => 'nullable',
        'application.docker_registry_image_tag' => 'nullable',
        'application.dockerfile_location' => 'nullable',
        'application.custom_labels' => 'nullable',
    ];
    protected $validationAttributes = [
        'application.name' => 'name',
        'application.description' => 'description',
        'application.fqdn' => 'FQDN',
        'application.git_repository' => 'Git repository',
        'application.git_branch' => 'Git branch',
        'application.git_commit_sha' => 'Git commit SHA',
        'application.install_command' => 'Install command',
        'application.build_command' => 'Build command',
        'application.start_command' => 'Start command',
        'application.build_pack' => 'Build pack',
        'application.static_image' => 'Static image',
        'application.base_directory' => 'Base directory',
        'application.publish_directory' => 'Publish directory',
        'application.ports_exposes' => 'Ports exposes',
        'application.ports_mappings' => 'Ports mappings',
        'application.dockerfile' => 'Dockerfile',
        'application.docker_registry_image_name' => 'Docker registry image name',
        'application.docker_registry_image_tag' => 'Docker registry image tag',
        'application.dockerfile_location' => 'Dockerfile location',
        'application.custom_labels' => 'Custom labels',
    ];

    public function mount()
    {
        if (is_null(data_get($this->application, 'custom_labels'))) {
            $this->customLabels = str(implode(",", generateLabelsApplication($this->application)))->replace(',', "\n");
        } else {
            $this->customLabels = str($this->application->custom_labels)->replace(',', "\n");
        }
        if (data_get($this->application, 'settings')) {
            $this->is_static = $this->application->settings->is_static;
            $this->is_git_submodules_enabled = $this->application->settings->is_git_submodules_enabled;
            $this->is_git_lfs_enabled = $this->application->settings->is_git_lfs_enabled;
            $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
            $this->is_preview_deployments_enabled = $this->application->settings->is_preview_deployments_enabled;
            $this->is_auto_deploy_enabled = $this->application->settings->is_auto_deploy_enabled;
            $this->is_force_https_enabled = $this->application->settings->is_force_https_enabled;
        }
        $this->checkLabelUpdates();
    }
    public function updatedApplicationBuildPack()
    {
        if ($this->application->build_pack !== 'nixpacks') {
            $this->application->settings->is_static = $this->is_static = false;
            $this->application->settings->save();
        }
        $this->submit();
    }
    public function checkLabelUpdates()
    {
        if (md5($this->application->custom_labels) !== md5(implode(",", generateLabelsApplication($this->application)))) {
            $this->labelsChanged = true;
        } else {
            $this->labelsChanged = false;
        }
    }
    public function instantSave()
    {
        // @TODO: find another way - if possible
        $this->application->settings->is_static = $this->is_static;
        if ($this->is_static) {
            $this->application->ports_exposes = 80;
        } else {
            $this->application->ports_exposes = 3000;
        }
        $this->application->settings->is_git_submodules_enabled = $this->is_git_submodules_enabled;
        $this->application->settings->is_git_lfs_enabled = $this->is_git_lfs_enabled;
        $this->application->settings->is_debug_enabled = $this->is_debug_enabled;
        $this->application->settings->is_preview_deployments_enabled = $this->is_preview_deployments_enabled;
        $this->application->settings->is_auto_deploy_enabled = $this->is_auto_deploy_enabled;
        $this->application->settings->is_force_https_enabled = $this->is_force_https_enabled;
        $this->application->settings->save();
        $this->application->save();
        $this->application->refresh();
        $this->emit('success', 'Application settings updated!');
        $this->checkLabelUpdates();
    }

    public function getWildcardDomain()
    {
        $server = data_get($this->application, 'destination.server');
        if ($server) {
            $fqdn = generateFqdn($server, $this->application->uuid);
            $this->application->fqdn = $fqdn;
            $this->application->save();
            $this->emit('success', 'Application settings updated!');
        }
    }
    public function resetDefaultLabels($showToaster = true)
    {
        $this->customLabels = str(implode(",", generateLabelsApplication($this->application)))->replace(',', "\n");
        $this->submit($showToaster);
    }

    public function updatedApplicationFqdn()
    {
        $this->resetDefaultLabels(false);
        $this->emit('success', 'Labels reseted to default!');
    }
    public function submit($showToaster = true)
    {
        try {
            $this->validate();
            if (data_get($this->application, 'build_pack') === 'dockerimage') {
                $this->validate([
                    'application.docker_registry_image_name' => 'required',
                    'application.docker_registry_image_tag' => 'required',
                ]);
            }
            if (data_get($this->application, 'fqdn')) {
                $domains = Str::of($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                    return Str::of($domain)->trim()->lower();
                });
                $this->application->fqdn = $domains->implode(',');
            }
            if (data_get($this->application, 'dockerfile')) {
                $port = get_port_from_dockerfile($this->application->dockerfile);
                if ($port && !$this->application->ports_exposes) {
                    $this->application->ports_exposes = $port;
                }
            }
            if ($this->application->base_directory && $this->application->base_directory !== '/') {
                $this->application->base_directory = rtrim($this->application->base_directory, '/');
            }
            if ($this->application->publish_directory && $this->application->publish_directory !== '/') {
                $this->application->publish_directory = rtrim($this->application->publish_directory, '/');
            }
            if (gettype($this->customLabels) === 'string') {
                $this->customLabels = str($this->customLabels)->replace(',', "\n");
            }
            $this->application->custom_labels = $this->customLabels->explode("\n")->implode(',');
            $this->application->save();
            $showToaster && $this->emit('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->checkLabelUpdates();
        }
    }
}
