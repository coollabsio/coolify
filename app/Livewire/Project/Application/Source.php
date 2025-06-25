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
    
    #[Validate(['required', 'string', 'in:deploy_key,https_basic,source'])]
    public string $gitAuthType = 'deploy_key';
    
    #[Validate(['nullable', 'string'])]
    public ?string $gitBasicAuthUsername = null;
    
    #[Validate(['nullable', 'string'])]
    public ?string $gitBasicAuthPassword = null;

    #[Locked]
    public $sources;

    public function mount()
    {
        try {
            $this->syncData();
            $this->getPrivateKeys();
            $this->getSources();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $updateData = [
                'git_repository' => $this->gitRepository,
                'git_branch' => $this->gitBranch,
                'git_commit_sha' => $this->gitCommitSha,
                'git_auth_type' => $this->gitAuthType,
            ];
            
            // Only update auth fields based on selected auth type
            if ($this->gitAuthType === 'deploy_key') {
                $updateData['private_key_id'] = $this->privateKeyId;
                $updateData['git_basic_auth_username'] = null;
                $updateData['git_basic_auth_password'] = null;
            } elseif ($this->gitAuthType === 'https_basic') {
                $updateData['git_basic_auth_username'] = $this->gitBasicAuthUsername;
                $updateData['git_basic_auth_password'] = $this->gitBasicAuthPassword;
                $updateData['private_key_id'] = null;
            }
            
            $this->application->update($updateData);
        } else {
            $this->gitRepository = $this->application->git_repository;
            $this->gitBranch = $this->application->git_branch;
            $this->gitCommitSha = $this->application->git_commit_sha;
            $this->gitAuthType = $this->application->git_auth_type ?? 'deploy_key';
            $this->privateKeyId = $this->application->private_key_id;
            $this->privateKeyName = data_get($this->application, 'private_key.name');
            $this->gitBasicAuthUsername = $this->application->git_basic_auth_username;
            $this->gitBasicAuthPassword = $this->application->git_basic_auth_password;
        }
    }

    private function getPrivateKeys()
    {
        $this->privateKeys = PrivateKey::whereTeamId(currentTeam()->id)->get()->reject(function ($key) {
            return $key->id == $this->privateKeyId;
        });
    }

    private function getSources()
    {
        // filter the current source out
        $this->sources = currentTeam()->sources()->whereNotNull('app_id')->reject(function ($source) {
            return $source->id === $this->application->source_id;
        })->sortBy('name');
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
            
            // Additional validation for HTTPS auth
            if ($this->gitAuthType === 'https_basic') {
                $this->validate([
                    'gitBasicAuthUsername' => 'required|string',
                    'gitBasicAuthPassword' => 'required|string',
                ]);
                
                // Validate HTTPS URL
                $parsed_url = parse_url($this->gitRepository);
                if (!$parsed_url || !isset($parsed_url['scheme']) || $parsed_url['scheme'] !== 'https') {
                    $this->dispatch('error', 'HTTPS authentication requires an HTTPS repository URL.');
                    return;
                }
            }
            $this->syncData(true);
            $this->dispatch('success', 'Application source updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changeSource($sourceId, $sourceType)
    {
        try {
            $this->application->update([
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'repository_project_id' => null,
            ]);
            $this->application->refresh();
            $this->getSources();
            $this->dispatch('success', 'Source updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    
    public function changeAuthType()
    {
        try {
            // Validate repository URL for HTTPS auth
            if ($this->gitAuthType === 'https_basic') {
                $parsed_url = parse_url($this->gitRepository);
                if (!$parsed_url || !isset($parsed_url['scheme']) || $parsed_url['scheme'] !== 'https') {
                    $this->dispatch('error', 'HTTPS authentication requires an HTTPS repository URL.');
                    return;
                }
            }
            
            $this->syncData(true);
            $this->dispatch('success', 'Authentication type updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
