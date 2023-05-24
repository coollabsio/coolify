<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use App\Models\InstanceSettings;
use Livewire\Component;
use Illuminate\Support\Str;
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
    public string|null $project_wildcard_domain = null;
    public string|null $global_wildcard_domain = null;

    public bool $is_static;
    public bool $is_git_submodules_allowed;
    public bool $is_git_lfs_allowed;
    public bool $is_debug;
    public bool $is_previews;
    public bool $is_auto_deploy;
    public bool $is_force_https;

    protected $rules = [
        'application.name' => 'required|min:6',
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
    ];
    public function instantSave()
    {
        // @TODO: find another way - if possible
        $this->application->settings->is_static = $this->is_static;
        $this->application->settings->is_git_submodules_allowed = $this->is_git_submodules_allowed;
        $this->application->settings->is_git_lfs_allowed = $this->is_git_lfs_allowed;
        $this->application->settings->is_debug = $this->is_debug;
        $this->application->settings->is_previews = $this->is_previews;
        $this->application->settings->is_auto_deploy = $this->is_auto_deploy;
        $this->application->settings->is_force_https = $this->is_force_https;
        $this->application->settings->save();
        $this->application->refresh();
        $this->emit('saved', 'Application settings updated!');
        $this->checkWildCardDomain();
    }
    protected function checkWildCardDomain()
    {
        $coolify_instance_settings = InstanceSettings::get();
        $this->project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
        $this->global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
        $this->wildcard_domain = $this->project_wildcard_domain ?? $this->global_wildcard_domain ?? null;
    }
    public function mount()
    {
        $this->is_static = $this->application->settings->is_static;
        $this->is_git_submodules_allowed = $this->application->settings->is_git_submodules_allowed;
        $this->is_git_lfs_allowed = $this->application->settings->is_git_lfs_allowed;
        $this->is_debug = $this->application->settings->is_debug;
        $this->is_previews = $this->application->settings->is_previews;
        $this->is_auto_deploy = $this->application->settings->is_auto_deploy;
        $this->is_force_https = $this->application->settings->is_force_https;
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
    }
    public function generateProjectRandomDomain()
    {
        // Set wildcard domain based on Project wildcard domain
        $url = Url::fromString($this->project_wildcard_domain);
        $host = $url->getHost();
        $path = $url->getPath() === '/' ? '' : $url->getPath();
        $scheme = $url->getScheme();
        $this->application->fqdn = $scheme . '://' . $this->application->uuid . '.' . $host . $path;
        $this->application->save();
    }
    public function submit()
    {
        try {
            $this->validate();

            $domains = Str::of($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                return Str::of($domain)->trim()->lower();
            });

            $this->application->fqdn = $domains->implode(',');
            $this->application->save();
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
