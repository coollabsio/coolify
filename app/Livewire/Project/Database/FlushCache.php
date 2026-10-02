<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\FlushCacheDatabase;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class FlushCache extends Component
{
    use AuthorizesRequests;

    public $database;

    public function mount(): void
    {
        $this->authorize('view', $this->database);
    }

    public function flush(): void
    {
        try {
            $this->authorize('manage', $this->database);

            if (! $this->isCacheDatabase()) {
                throw new \RuntimeException('This database type does not support cache flushing.');
            }

            FlushCacheDatabase::run($this->database);
            $this->auditFlush();
            $this->dispatch('success', 'Cache flushed successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function isCacheDatabase(): bool
    {
        return $this->database instanceof StandaloneRedis
            || $this->database instanceof StandaloneKeydb
            || $this->database instanceof StandaloneDragonfly;
    }

    private function auditFlush(): void
    {
        auditLog('ui.database.flushed', [
            'team_id' => $this->database->team()?->id,
            'database_uuid' => $this->database->uuid,
            'database_name' => $this->database->name,
        ]);
    }

    public function render(): View
    {
        return view('livewire.project.database.flush-cache');
    }
}
