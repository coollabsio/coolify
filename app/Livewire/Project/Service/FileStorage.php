<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
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
use Livewire\Component;

class FileStorage extends Component
{
    public LocalFileVolume $fileStorage;

    public ServiceApplication|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase|Application $resource;

    public string $fs_path;

    public ?string $workdir = null;

    public bool $permanently_delete = true;

    protected $rules = [
        'fileStorage.is_directory' => 'required',
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'fileStorage.content' => 'nullable',
        'fileStorage.is_based_on_git' => 'required|boolean',
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
        $this->fileStorage->loadStorageOnServer();
    }

    public function convertToDirectory()
    {
        try {
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

    public function convertToFile()
    {
        try {
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

    public function delete()
    {
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
        $original = $this->fileStorage->getOriginal();
        try {
            $this->validate();
            if ($this->fileStorage->is_directory) {
                $this->fileStorage->content = null;
            }
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->dispatch('success', 'File updated.');
        } catch (\Throwable $e) {
            $this->fileStorage->setRawAttributes($original);
            $this->fileStorage->save();

            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function render()
    {
        return view('livewire.project.service.file-storage');
    }
}
