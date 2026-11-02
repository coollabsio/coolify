<?php

namespace App\Livewire\Concerns;

use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

trait ManagesDatabaseBackup
{
    /**
     * Turn the backup schedule on or off and return the new state.
     */
    protected function toggleDatabaseBackup(ScheduledDatabaseBackup $backup): bool
    {
        $this->authorize('manageBackups', $backup->database);

        $backup->enabled = ! $backup->enabled;
        $changedFields = auditChangedFields($backup);
        $backup->save();

        $database = $backup->database;
        auditLog('ui.database.backup_schedule_updated', [
            'team_id' => $database?->team()?->id,
            'database_uuid' => $database?->uuid,
            'database_name' => $database?->name,
            'backup_uuid' => $backup->uuid,
            'changed_fields' => $changedFields,
        ]);
        $this->dispatch('success', $backup->enabled ? 'Backup enabled.' : 'Backup disabled.');

        return $backup->enabled;
    }

    /**
     * Queue a backup run now and redirect to the executions page of the backup.
     */
    protected function queueDatabaseBackup(ScheduledDatabaseBackup $backup): Redirector|RedirectResponse|null
    {
        $this->authorize('manageBackups', $backup->database);

        $database = $backup->database->refresh();
        if ($database->id !== 0 && ! str($database->status)->startsWith('running')) {
            $this->dispatch('error', 'The database must be running to start a backup.');

            return null;
        }

        DatabaseBackupJob::dispatch($backup);
        auditLog('ui.database.backup_started', [
            'team_id' => $database->team()?->id,
            'database_uuid' => $database->uuid,
            'database_name' => $database->name,
            'backup_uuid' => $backup->uuid,
        ]);
        $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');

        if ($database instanceof ServiceDatabase) {
            return redirect()->route('project.service.database.backup.executions', [
                'project_uuid' => $database->service->project()->uuid,
                'environment_uuid' => $database->service->environment->uuid,
                'service_uuid' => $database->service->uuid,
                'stack_service_uuid' => $database->uuid,
                'backup_uuid' => $backup->uuid,
            ]);
        }

        // Instance databases (e.g. coolify-db) have no project/environment.
        // Stay on the current page (settings.backup) instead of redirecting.
        $project = $database->project();
        $environment = $database->environment;
        if (! $project || ! $environment) {
            return null;
        }

        return redirect()->route('project.database.backup.executions', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
            'backup_uuid' => $backup->uuid,
        ]);
    }
}
