<?php

namespace App\Http\Livewire\Project\Service;

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

    protected $rules = [
        'fileStorage.is_directory' => 'required',
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'fileStorage.content' => 'nullable',
    ];
    public function mount()
    {
        $this->service = $this->fileStorage->service;
        $this->fs_path = Str::of($this->fileStorage->fs_path)->beforeLast('/');
        $file = Str::of($this->fileStorage->fs_path)->afterLast('/');
        if (Str::of($this->fs_path)->startsWith('.')) {
            $this->fs_path = Str::of($this->fs_path)->after('.');
            $this->fs_path = $this->service->service->workdir() . $this->fs_path . "/" . $file;
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
            $this->fileStorage->saveStorageOnServer($this->service);
            // ray($this->fileStorage);
            // $this->service->saveFileVolumes();
            $this->emit('success', 'File updated successfully.');
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
