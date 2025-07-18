<?php

namespace App\Livewire\Team\Storage;

use App\Models\S3Storage;
use Livewire\Component;

class Show extends Component
{
    public $storage = null;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
    }

    public function render()
    {
        return view('livewire.storage.show');
    }
}
