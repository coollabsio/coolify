<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

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

    public bool $is_static;
    public bool $is_git_submodules_allowed;
    public bool $is_git_lfs_allowed;
    public bool $is_debug;
    public bool $is_previews;
    public bool $is_bot;
    public bool $is_custom_ssl;
    public bool $is_http2;
    public bool $is_auto_deploy;
    public bool $is_dual_cert;

    protected $rules = [
        'application.name' => 'required|min:6',
        'application.fqdn' => 'nullable',
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
        'application.build_pack' => 'required',
        'application.static_image' => 'required',
        'application.base_directory' => 'required',
        'application.publish_directory' => 'nullable',
        'application.ports_exposes' => 'required',
        'application.ports_mappings' => 'nullable',
    ];
    public function instantSave()
    {
        // @TODO: find another way
        $this->application->settings->is_static = $this->is_static;
        $this->application->settings->is_git_submodules_allowed = $this->is_git_submodules_allowed;
        $this->application->settings->is_git_lfs_allowed = $this->is_git_lfs_allowed;
        $this->application->settings->is_debug = $this->is_debug;
        $this->application->settings->is_previews = $this->is_previews;
        $this->application->settings->is_bot = $this->is_bot;
        $this->application->settings->is_custom_ssl = $this->is_custom_ssl;
        $this->application->settings->is_http2 = $this->is_http2;
        $this->application->settings->is_auto_deploy = $this->is_auto_deploy;
        $this->application->settings->is_dual_cert = $this->is_dual_cert;
        $this->application->settings->save();
        $this->application->refresh();
    }
    public function mount()
    {
        $this->application = Application::where('id', $this->applicationId)->with('destination', 'settings')->firstOrFail();
        $this->is_static = $this->application->settings->is_static;
        $this->is_git_submodules_allowed = $this->application->settings->is_git_submodules_allowed;
        $this->is_git_lfs_allowed = $this->application->settings->is_git_lfs_allowed;
        $this->is_debug = $this->application->settings->is_debug;
        $this->is_previews = $this->application->settings->is_previews;
        $this->is_bot = $this->application->settings->is_bot;
        $this->is_custom_ssl = $this->application->settings->is_custom_ssl;
        $this->is_http2 = $this->application->settings->is_http2;
        $this->is_auto_deploy = $this->application->settings->is_auto_deploy;
        $this->is_dual_cert = $this->application->settings->is_dual_cert;
    }
    public function submit()
    {
        $this->validate();
        $this->application->save();
    }
}
