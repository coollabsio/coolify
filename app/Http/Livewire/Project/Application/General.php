<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use App\Models\InstanceSettings;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Url\Url;

class General extends Component
{
    public string $applicationId;

    public Application $application;
    public string $name;
    public string|null $fqdn;
    public string $git_repository;
    public string $git_branch;
    public string|null $git_commit_sha;
    public string $build_pack;
    public string|null $wildcard_domain = null;
    public string|null $server_wildcard_domain = null;
    public string|null $global_wildcard_domain = null;

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
    ];

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
        $this->checkWildCardDomain();
    }

    protected function checkWildCardDomain()
    {
        $coolify_instance_settings = InstanceSettings::get();
        $this->server_wildcard_domain = data_get($this->application, 'destination.server.settings.wildcard_domain');
        $this->global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
        $this->wildcard_domain = $this->server_wildcard_domain ?? $this->global_wildcard_domain ?? null;
    }

    public function mount()
    {
        $this->is_static = $this->application->settings->is_static;
        $this->is_git_submodules_enabled = $this->application->settings->is_git_submodules_enabled;
        $this->is_git_lfs_enabled = $this->application->settings->is_git_lfs_enabled;
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
        $this->is_preview_deployments_enabled = $this->application->settings->is_preview_deployments_enabled;
        $this->is_auto_deploy_enabled = $this->application->settings->is_auto_deploy_enabled;
        $this->is_force_https_enabled = $this->application->settings->is_force_https_enabled;
        $this->checkWildCardDomain();
    }

    public function generateGlobalRandomDomain()
    {
        // Set wildcard domain based on Global wildcard domain
        $url = Url::fromString($this->global_wildcard_domain);
        $host = $url->getHost();
        $path = $url->getPath() === '/' ? '' : $url->getPath();
        $scheme = $url->getScheme();
        $this->application->fqdn = $scheme . '://' . $this->application->uuid . '.' . $host . $path;
        $this->application->save();
        $this->emit('success', 'Application settings updated!');
    }

    public function generateServerRandomDomain()
    {
        // Set wildcard domain based on Server wildcard domain
        $url = Url::fromString($this->server_wildcard_domain);
        $host = $url->getHost();
        $path = $url->getPath() === '/' ? '' : $url->getPath();
        $scheme = $url->getScheme();
        $this->application->fqdn = $scheme . '://' . $this->application->uuid . '.' . $host . $path;
        $this->application->save();
        $this->emit('success', 'Application settings updated!');
    }

    public function submit()
    {
        ray($this->application);
        try {
            $this->validate();
            if (data_get($this->application,'fqdn')) {
                $domains = Str::of($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                    return Str::of($domain)->trim()->lower();
                });
                $this->application->fqdn = $domains->implode(',');
            }
            if ($this->application->dockerfile) {
                $port = get_port_from_dockerfile($this->application->dockerfile);
                if ($port) {
                    $this->application->ports_exposes = $port;
                }
            }
            if ($this->application->base_directory && $this->application->base_directory !== '/') {
                $this->application->base_directory = rtrim($this->application->base_directory, '/');
            }
            if ($this->application->publish_directory && $this->application->publish_directory !== '/') {
                $this->application->publish_directory = rtrim($this->application->publish_directory, '/');
            }
            $this->application->save();
            $this->emit('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
