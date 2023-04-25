<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Source extends Component
{
    public $applicationId;
    public Application $application;

    protected $rules = [
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
    ];
    public function mount()
    {
        $this->application = Application::find($this->applicationId)->first();
    }
}
