<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Livewire\Component;

class Index extends Component
{
    public $s3;

    public function mount()
    {
        $this->s3 = S3Storage::ownedByCurrentTeam()->get();
    }

    public function render()
    {
        return view('livewire.storage.index');
    }
}
