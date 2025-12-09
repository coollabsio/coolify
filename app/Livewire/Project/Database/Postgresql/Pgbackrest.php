<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Pgbackrest extends Component
{
    use AuthorizesRequests;

    public StandalonePostgresql $database;

    public string $logLevel = 'info';

    public string $compressType = 'lz4';

    public int $compressLevel = 6;

    protected function rules(): array
    {
        return [
            'logLevel' => 'required|string|in:off,error,warn,info,detail,debug,trace',
            'compressType' => 'required|string|in:none,bz2,gz,lz4,zst',
            'compressLevel' => 'required|integer|min:0|max:9',
        ];
    }

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }

    public function mount()
    {
        $this->authorize('view', $this->database);
        $this->syncData();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->pgbackrest_log_level = $this->logLevel;
            $this->database->pgbackrest_compress_type = $this->compressType;
            $this->database->pgbackrest_compress_level = $this->compressLevel;
            $this->database->save();
        } else {
            $this->logLevel = $this->database->pgbackrest_log_level ?? 'info';
            $this->compressType = $this->database->pgbackrest_compress_type ?? 'lz4';
            $this->compressLevel = $this->database->pgbackrest_compress_level ?? 6;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);

            $this->syncData(true);
            $this->dispatch('success', 'pgBackRest settings updated. Please restart the database to apply changes.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.database.postgresql.pgbackrest');
    }
}
