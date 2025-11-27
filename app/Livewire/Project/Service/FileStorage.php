<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;

class FileStorage extends Component
{
    use AuthorizesRequests;

    public LocalFileVolume $fileStorage;

    public ServiceApplication|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase|Application $resource;

    public string $fs_path;

    public ?string $workdir = null;

    public bool $permanently_delete = true;

    public bool $isReadOnly = false;

    #[Validate(['nullable'])]
    public ?string $content = null;

    #[Validate(['required', 'boolean'])]
    public bool $isBasedOnGit = false;

    protected $rules = [
        'fileStorage.is_directory' => 'required',
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'content' => 'nullable',
        'isBasedOnGit' => 'required|boolean',
    ];

    public function mount()
    {
        $this->resource = $this->fileStorage->service;
        if (str($this->fileStorage->fs_path)->startsWith('.')) {
            $this->workdir = $this->resource->service?->workdir();
            $this->fs_path = str($this->fileStorage->fs_path)->after('.');
        } else {
            $this->workdir = null;
            $this->fs_path = $this->fileStorage->fs_path;
        }

        $this->isReadOnly = $this->fileStorage->isReadOnlyVolume();
        $this->syncData();
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Sync to model
            $this->fileStorage->content = $this->content;
            $this->fileStorage->is_based_on_git = $this->isBasedOnGit;

            $this->fileStorage->save();
        } else {
            // Sync from model
            $this->content = $this->fileStorage->content;
            $this->isBasedOnGit = $this->fileStorage->is_based_on_git;
        }
    }

    public function convertToDirectory()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->fileStorage->deleteStorageOnServer();
            $this->fileStorage->is_directory = true;
            $this->fileStorage->content = null;
            $this->fileStorage->is_based_on_git = false;
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshStorages');
        }
    }

    public function loadStorageOnServer()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->fileStorage->loadStorageOnServer();
            $this->syncData();
            $this->dispatch('success', 'File storage loaded from server.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshStorages');
        }
    }

    public function convertToFile()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->fileStorage->deleteStorageOnServer();
            $this->fileStorage->is_directory = false;
            $this->fileStorage->content = null;
            if (data_get($this->resource, 'settings.is_preserve_repository_enabled')) {
                $this->fileStorage->is_based_on_git = true;
            }
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshStorages');
        }
    }

    public function delete($password)
    {
        $this->authorize('update', $this->resource);

        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        try {
            $message = 'File deleted.';
            if ($this->fileStorage->is_directory) {
                $message = 'Directory deleted.';
            }
            if ($this->permanently_delete) {
                $message = 'Directory deleted from the server.';
                $this->fileStorage->deleteStorageOnServer();
            }
            $this->fileStorage->delete();
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshStorages');
        }
    }

    public function submit()
    {
        $this->authorize('update', $this->resource);

        $original = $this->fileStorage->getOriginal();
        try {
            $this->validate();
            if ($this->fileStorage->is_directory) {
                $this->content = null;
            }
            // Sync component properties to model
            $this->fileStorage->content = $this->content;
            $this->fileStorage->is_based_on_git = $this->isBasedOnGit;
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->dispatch('success', 'File updated.');
        } catch (\Throwable $e) {
            $this->fileStorage->setRawAttributes($original);
            $this->fileStorage->save();
            $this->syncData();

            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function render()
    {
        return view('livewire.project.service.file-storage', [
            'directoryDeletionCheckboxes' => [
                ['id' => 'permanently_delete', 'label' => 'The selected directory and all its contents will be permantely deleted form the server.'],
            ],
            'fileDeletionCheckboxes' => [
                ['id' => 'permanently_delete', 'label' => 'The selected file will be permanently deleted form the server.'],
            ],
        ]);
    }
}
