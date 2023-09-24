<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use Livewire\Component;

class FileStorage extends Component
{
    public LocalFileVolume $fileStorage;

    protected $rules = [
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'fileStorage.content' => 'nullable',
    ];
    public function render()
    {
        return view('livewire.project.service.file-storage');
    }
}
