<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public string $search = '';

    public bool $scheduleModalOpen = false;

    public ?ScheduledDatabaseBackup $selectedDatabaseBackup = null;

    public ?ScheduledVolumeBackup $selectedVolumeBackup = null;

    public ?Collection $s3s = null;

    public function getListeners(): array
    {
        $teamId = currentTeam()->id;

        return [
            'refreshVolumeBackups' => '$refresh',
            'modalClosed' => 'closeScheduleModal',
            "echo-private:team.{$teamId},BackupCreated" => '$refresh',
        ];
    }

    public function mount(?Service $service = null): void
    {
        $this->service = $service ?? $this->findService();
        $this->authorize('view', $this->service);
        $this->parameters = get_route_parameters();
        $this->search = request()->string('search')->toString();

    }

    public function openSchedule(string $backupUuid): void
    {
        $this->loadSelectedSchedule($backupUuid);
        $this->s3s = currentTeam()->s3s;
        $this->scheduleModalOpen = true;
    }

    public function closeScheduleModal(): void
    {
        $this->scheduleModalOpen = false;
        $this->selectedDatabaseBackup = null;
        $this->selectedVolumeBackup = null;
    }

    public function backupNow(string $type, string $backupUuid): void
    {
        try {
            if ($type === 'database') {
                $this->loadSelectedSchedule($backupUuid);
                abort_unless($this->selectedDatabaseBackup, 404);
                $this->authorize('manageBackups', $this->selectedDatabaseBackup->database);
                DatabaseBackupJob::dispatch($this->selectedDatabaseBackup);
            } else {
                abort_unless($type === 'storage', 404);
                $this->loadSelectedSchedule($backupUuid);
                abort_unless($this->selectedVolumeBackup, 404);
                $this->authorize('update', $this->selectedVolumeBackup->targetResource());
                VolumeBackupJob::dispatch($this->selectedVolumeBackup);
            }

            $this->selectedDatabaseBackup = null;
            $this->selectedVolumeBackup = null;
            $this->dispatch('success', 'Backup queued.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        $databaseTargets = $this->service->databases->filter(
            fn (ServiceDatabase $database): bool => $database->isBackupSolutionAvailable(),
        );

        $databaseBackups = ScheduledDatabaseBackup::query()
            ->with(['database', 'latest_log', 's3'])
            ->withCount('executions')
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->latest()
            ->get();

        $backups = ScheduledVolumeBackup::query()
            ->with(['backupable.resource', 'latestExecution', 's3'])
            ->withCount('executions')
            ->forService($this->service)
            ->latest()
            ->get();

        return view('livewire.project.service.volume-backup.index', [
            'backups' => $backups,
            'databaseBackups' => $databaseBackups,
            'databaseTargets' => $databaseTargets,
        ]);
    }

    private function findService(): Service
    {
        $project = currentTeam()->projects()->where('uuid', request()->route('project_uuid'))->firstOrFail();
        $environment = $project->environments()->where('uuid', request()->route('environment_uuid'))->firstOrFail();

        return $environment->services()
            ->with(['server.settings', 'environment.project'])
            ->where('uuid', request()->route('service_uuid'))
            ->firstOrFail();
    }

    private function loadSelectedSchedule(string $backupUuid): void
    {
        $this->selectedDatabaseBackup = ScheduledDatabaseBackup::query()
            ->with('database')
            ->whereUuid($backupUuid)
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->first();

        if ($this->selectedDatabaseBackup) {
            return;
        }

        $this->selectedVolumeBackup = ScheduledVolumeBackup::query()
            ->with('backupable.resource')
            ->whereUuid($backupUuid)
            ->forService($this->service)
            ->firstOrFail();
    }
}
