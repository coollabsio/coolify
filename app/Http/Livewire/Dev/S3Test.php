<?php

namespace App\Http\Livewire\Dev;

use App\Models\S3Storage;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class S3Test extends Component
{
    use WithFileUploads;

    public $s3;
    public $file;

    public function mount()
    {
        $this->s3 = S3Storage::first();
    }

    public function save()
    {
        try {
            $this->validate([
                'file' => 'required|max:150', // 1MB Max
            ]);
            set_s3_target($this->s3);
            $this->file->storeAs('files', $this->file->getClientOriginalName(), 'custom-s3');
            $this->emit('success', 'File uploaded successfully.');
        } catch (\Throwable $th) {
            return general_error_handler($th, $this, false);
        }

    }

    public function get_files()
    {
        set_s3_target($this->s3);
        dd(Storage::disk('custom-s3')->files('files'));
    }
}
