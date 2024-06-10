<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\PrivateKey;
use Livewire\Component;

class Source extends Component
{
    public $applicationId;

    public Application $application;

    public $private_keys;

    protected $rules = [
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
    ];

    protected $validationAttributes = [
        'application.git_repository' => 'repository',
        'application.git_branch' => 'branch',
        'application.git_commit_sha' => 'commit sha',
    ];

    public function mount()
    {
        $this->get_private_keys();
    }

    private function get_private_keys()
    {
        $this->private_keys = PrivateKey::whereTeamId(currentTeam()->id)->get()->reject(function ($key) {
            return $key->id == $this->application->private_key_id;
        });
    }

    public function setPrivateKey(int $private_key_id)
    {
        $this->application->private_key_id = $private_key_id;
        $this->application->save();
        $this->application->refresh();
        $this->get_private_keys();
    }

    public function submit()
    {
        $this->validate();
        if (! $this->application->git_commit_sha) {
            $this->application->git_commit_sha = 'HEAD';
        }
        $this->application->save();
        $this->dispatch('success', 'Application source updated!');
    }
}
