<?php

namespace App\Http\Livewire\Application;

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

    protected $rules = [
        'application.name' => 'required|min:6',
        'application.fqdn' => 'nullable',
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
        'application.build_pack' => 'required',
        'application.base_directory' => 'required',
        'application.publish_directory' => 'nullable',
        'application.environment.name' => 'required',
        'application.destination.network' => 'required',
    ];

    public function mount()
    {
        $this->application = Application::find($this->applicationId)->with('destination')->first();
    }
    public function submit()
    {
        $this->validate();
        $this->application->save();
    }
}
