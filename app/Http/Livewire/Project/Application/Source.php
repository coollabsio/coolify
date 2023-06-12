<?php

namespace App\Http\Livewire\Project\Application;

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
    private function get_private_keys()
    {
        $this->private_keys = PrivateKey::whereTeamId(session('currentTeam')->id)->get()->reject(function ($key) {
            return $key->id == $this->application->private_key_id;
        });
    }
    public function mount()
    {
        $this->get_private_keys();
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
        if (!$this->application->git_commit_sha) {
            $this->application->git_commit_sha = 'HEAD';
        }
        $this->application->save();
        $this->emit('saved', 'Application source updated!');
    }
}
