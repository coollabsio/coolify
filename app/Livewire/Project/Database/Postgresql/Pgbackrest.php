<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Actions\Database\Pgbackrest\RestoreFromPgbackrest;
use App\Jobs\PgbackrestStanzaJob;
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

    public string $logLevel = 'info';

    public string $compressType = 'lz4';

    public int $compressLevel = 6;

    public array $backups = [];

    public bool $isLoading = false;

    public ?string $stanzaStatus = null;

    protected function rules(): array
    {
        return [
            'pgbackrestEnabled' => 'boolean',
            'retentionFull' => 'required|integer|min:1|max:100',
            'retentionDiff' => 'required|integer|min:1|max:100',
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
        $this->loadBackups();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->pgbackrest_enabled = $this->pgbackrestEnabled;
            $this->database->pgbackrest_retention_full = $this->retentionFull;
            $this->database->pgbackrest_retention_diff = $this->retentionDiff;
            $this->database->pgbackrest_log_level = $this->logLevel;
            $this->database->pgbackrest_compress_type = $this->compressType;
            $this->database->pgbackrest_compress_level = $this->compressLevel;
            $this->database->save();
        } else {
            $this->pgbackrestEnabled = $this->database->pgbackrest_enabled ?? false;
            $this->retentionFull = $this->database->pgbackrest_retention_full ?? 2;
            $this->retentionDiff = $this->database->pgbackrest_retention_diff ?? 7;
            $this->logLevel = $this->database->pgbackrest_log_level ?? 'info';
            $this->compressType = $this->database->pgbackrest_compress_type ?? 'lz4';
            $this->compressLevel = $this->database->pgbackrest_compress_level ?? 6;
        }
    }

    public function loadBackups()
    {
        if (! $this->database->isPgbackrestEnabled()) {
            $this->backups = [];

            return;
        }

        try {
            $restoreAction = new RestoreFromPgbackrest;
            $result = $restoreAction->getAvailableBackups($this->database);
            if ($result['success']) {
                $this->backups = $result['backups'];
            } else {
                $this->backups = [];
            }
        } catch (\Throwable $e) {
            $this->backups = [];
        }
    }

    public function refreshBackups()
    {
        $this->loadBackups();
        $this->dispatch('success', 'Backup list refreshed.');
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

    public function initializeStanza()
    {
        try {
            $this->authorize('update', $this->database);

            if (! $this->database->isPgbackrestEnabled()) {
                $this->dispatch('error', 'pgBackRest must be enabled first.');

                return;
            }

            $status = str($this->database->status);
            if (! $status->startsWith('running')) {
                $this->dispatch('error', 'Database must be running to initialize stanza.');

                return;
            }

            PgbackrestStanzaJob::dispatch($this->database, 'create');

            $this->dispatch('success', 'Stanza initialization job queued. This may take a few moments.');
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
