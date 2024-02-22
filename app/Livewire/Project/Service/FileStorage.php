<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Livewire\Component;
use Illuminate\Support\Str;

class FileStorage extends Component
{
    public LocalFileVolume $fileStorage;
    public ServiceApplication|ServiceDatabase $service;
    public string $fs_path;
    public ?string $workdir = null;

    protected $rules = [
        'fileStorage.is_directory' => 'required',
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'fileStorage.content' => 'nullable',
    ];
    public function mount()
    {
        $this->service = $this->fileStorage->service;
         if (Str::of($this->fileStorage->fs_path)->startsWith('.')) {
            $this->workdir = $this->service->service->workdir();
            $this->fs_path = Str::of($this->fileStorage->fs_path)->after('.');
         } else {
            $this->workdir = null;
            $this->fs_path = $this->fileStorage->fs_path;
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
