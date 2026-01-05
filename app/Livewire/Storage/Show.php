<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public $storage = null;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
        $this->authorize('view', $this->storage);
    }

    public function render()
    {
        return view('livewire.storage.show');
    }
}
