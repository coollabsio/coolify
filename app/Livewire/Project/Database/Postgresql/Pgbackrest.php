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

    public bool $pgbackrestEnabled = false;

    public int $retentionFull = 2;

    public int $retentionDiff = 7;

    public string $retentionFullType = 'count';

    public string $retentionArchiveType = 'full';

    public ?int $retentionArchive = null;

    public string $logLevel = 'info';

    public string $compressType = 'lz4';

    public int $compressLevel = 6;

    protected function rules(): array
    {
        return [
            'pgbackrestEnabled' => 'boolean',
            'retentionFull' => 'required|integer|min:1|max:9999999',
            'retentionDiff' => 'required|integer|min:1|max:9999999',
            'retentionFullType' => 'required|string|in:count,time',
            'retentionArchiveType' => 'required|string|in:full,diff,incr',
            'retentionArchive' => 'nullable|integer|min:1|max:9999999',
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
            $this->database->pgbackrest_enabled = $this->pgbackrestEnabled;
            $this->database->pgbackrest_retention_full = $this->retentionFull;
            $this->database->pgbackrest_retention_diff = $this->retentionDiff;
            $this->database->pgbackrest_retention_full_type = $this->retentionFullType;
            $this->database->pgbackrest_retention_archive_type = $this->retentionArchiveType;
            $this->database->pgbackrest_retention_archive = $this->retentionArchive;
            $this->database->pgbackrest_log_level = $this->logLevel;
            $this->database->pgbackrest_compress_type = $this->compressType;
            $this->database->pgbackrest_compress_level = $this->compressLevel;
            $this->database->save();
        } else {
            $this->pgbackrestEnabled = $this->database->pgbackrest_enabled ?? false;
            $this->retentionFull = $this->database->pgbackrest_retention_full ?? 2;
            $this->retentionDiff = $this->database->pgbackrest_retention_diff ?? 7;
            $this->retentionFullType = $this->database->pgbackrest_retention_full_type ?? 'count';
            $this->retentionArchiveType = $this->database->pgbackrest_retention_archive_type ?? 'full';
            $this->retentionArchive = $this->database->pgbackrest_retention_archive;
            $this->logLevel = $this->database->pgbackrest_log_level ?? 'info';
            $this->compressType = $this->database->pgbackrest_compress_type ?? 'lz4';
            $this->compressLevel = $this->database->pgbackrest_compress_level ?? 6;
        }
    }

    public function togglePgbackrest()
    {
        try {
            $this->authorize('update', $this->database);

            $this->syncData(true);

            $this->dispatch(
                'success',
                $this->pgbackrestEnabled
                    ? 'pgBackRest enabled. Please restart the database to apply changes.'
                    : 'pgBackRest disabled. Please restart the database to apply changes.',
            );

            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
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
