<?php

namespace App\Livewire\Project\Database\Backup;

use App\Livewire\Concerns\ManagesDatabaseBackup;
use App\Livewire\Project\Database\BackupEdit;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Execution extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use ManagesDatabaseBackup;

    public $database;

    public ?ScheduledDatabaseBackup $backup;

    public $executions;

    public $s3s;

    public array $parameters = [];

    public string $section = 'general';

    public function getListeners(): array
    {
        // Keep the header backup actions in sync when the database starts/stops.
        $listeners = [
            'databaseUpdated' => '$refresh',
            'backupEnabledChanged' => 'syncBackupEnabled',
        ];

        $user = Auth::user();
        if ($user) {
            $listeners["echo-private:user.{$user->id},DatabaseStatusChanged"] = '$refresh';
        }

        return [
            ...$listeners,
            ...$this->teamChannelListeners([
                'ServiceChecked' => '$refresh',
                'ServiceStatusChanged' => '$refresh',
            ]),
        ];
    }

    public function mount()
    {
        $backup_uuid = request()->route('backup_uuid');
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            abort(404);
        }
        $environment->load(['applications']);
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }
        $backup = $database->scheduledBackups->where('uuid', $backup_uuid)->first();
        if (! $backup) {
            return redirect()->route('dashboard');
        }
        $executions = collect($backup->executions)->sortByDesc('created_at');
        $this->database = $database;
        $this->backup = $backup;
        $this->executions = $executions;
        $this->s3s = S3Storage::query()
            ->where('team_id', $database->team()->id)
            ->where('is_usable', true)
            ->get();
        $this->parameters = get_route_parameters();
        $this->section = match (request()->route()?->getName()) {
            'project.database.backup.executions' => 'executions',
            'project.database.backup.danger' => 'danger',
            default => 'general',
        };
    }

    public function backupNow(): Redirector|RedirectResponse|null
    {
        try {
            return $this->queueDatabaseBackup($this->backup);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
    }

    public function toggleEnabled(): void
    {
        try {
            $this->toggleDatabaseBackup($this->backup);
            $this->dispatch('backupEnabledChanged')->to(BackupEdit::class);
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function syncBackupEnabled(): void
    {
        $this->backup->refresh();
    }

    public function render()
    {
        return view('livewire.project.database.backup.execution');
    }
}
