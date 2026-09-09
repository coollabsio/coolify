<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public $storage = null;

    public string $currentRoute = '';

    public int $backupCount = 0;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
        try {
            $this->authorize('view', $this->storage);
        } catch (AuthorizationException) {
            return $this->redirectRoute('storage.index', navigate: true);
        }
        $this->currentRoute = request()->route()->getName();
        $this->backupCount = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)->count()
            + ScheduledVolumeBackup::where('s3_storage_id', $this->storage->id)->count();
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->storage);

            $this->storage->delete();

            return redirectRoute($this, 'storage.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[On('storage-status-changed')]
    public function refreshStorageStatus(bool $isUsable): void
    {
        $this->storage->refresh();
    }

    public function render()
    {
        return view('livewire.storage.show');
    }
}
