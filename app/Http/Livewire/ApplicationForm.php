<?php

namespace App\Http\Livewire;

use App\Models\Application;
use Livewire\Component;

class ApplicationForm extends Component
{
    protected Application $application;
    public string $applicationId;
    public string $name;
    public string|null $fqdn;
    public string $git_repository;
    public string $git_branch;
    public string|null $git_commit_sha;

    protected $rules = [
        'name' => 'required|min:6'
    ];
    public function mount()
    {
        $this->application = Application::find($this->applicationId);
        $this->fill([
            'name' => $this->application->name,
            'fqdn' => $this->application->fqdn,
            'git_repository' => $this->application->git_repository,
            'git_branch' => $this->application->git_branch,
            'git_commit_sha' => $this->application->git_commit_sha,
        ]);
    }
    public function submit()
    {
        $this->validate();
        dd($this->name);
    }
}
