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

    public function submit()
    {
        $this->validate();
        if (!$this->application->git_commit_sha) {
            $this->application->git_commit_sha = 'HEAD';
        }
        $this->application->save();
        $this->emit('saved', 'Application source updated!');
    }
}
