<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\PrivateKey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Source extends Component
{
    public Application $application;

    #[Locked]
    public $privateKeys;

    #[Validate(['nullable', 'string'])]
    public ?string $privateKeyName = null;

    #[Validate(['nullable', 'integer'])]
    public ?int $privateKeyId = null;

    #[Validate(['required', 'string'])]
    public string $gitRepository;

    #[Validate(['required', 'string'])]
    public string $gitBranch;

    #[Validate(['nullable', 'string'])]
    public ?string $gitCommitSha = null;

    public function mount()
    {
        try {
            $this->syncData();
            $this->getPrivateKeys();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->application->update([
                'git_repository' => $this->gitRepository,
                'git_branch' => $this->gitBranch,
                'git_commit_sha' => $this->gitCommitSha,
                'private_key_id' => $this->privateKeyId,
            ]);
        } else {
            $this->gitRepository = $this->application->git_repository;
            $this->gitBranch = $this->application->git_branch;
            $this->gitCommitSha = $this->application->git_commit_sha;
            $this->privateKeyId = $this->application->private_key_id;
            $this->privateKeyName = data_get($this->application, 'private_key.name');
        }
    }

    private function getPrivateKeys()
    {
        $this->privateKeys = PrivateKey::whereTeamId(currentTeam()->id)->get()->reject(function ($key) {
            return $key->id == $this->privateKeyId;
        });
    }

    public function setPrivateKey(int $privateKeyId)
    {
        try {
            $this->privateKeyId = $privateKeyId;
            $this->syncData(true);
            $this->getPrivateKeys();
            $this->application->refresh();
            $this->privateKeyName = $this->application->private_key->name;
            $this->dispatch('success', 'Private key updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (str($this->gitCommitSha)->isEmpty()) {
                $this->gitCommitSha = 'HEAD';
            }
            $this->syncData(true);
            $this->dispatch('success', 'Application source updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
