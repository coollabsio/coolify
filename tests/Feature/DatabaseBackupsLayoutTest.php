<?php

it('matches the storage backup overview layout', function () {
    $index = file_get_contents(resource_path('views/livewire/project/database/backup/index.blade.php'));
    $schedules = file_get_contents(resource_path('views/livewire/project/database/scheduled-backups.blade.php'));
    $component = file_get_contents(app_path('Livewire/Project/Database/Backup/Index.php'));

    expect($index)
        ->toContain('title="Database backups"')
        ->toContain('Schedules')
        ->toContain('Enabled')
        ->toContain('Total executions')
        ->toContain('application-settings-form flex min-w-0 flex-col gap-6')
        ->and($schedules)
        ->toContain('placeholder="Search backups"')
        ->toContain('data-table overflow-hidden rounded-xl border')
        ->toContain('<span class="block truncate text-[12px] font-semibold')
        ->toContain("route('project.database.backup.execution'")
        ->toContain("route('project.service.database.backup.show'")
        ->toContain("route('project.database.backup.executions'")
        ->toContain("route('project.service.database.backup.executions'")
        ->toContain('<a wire:navigate href="{{ $backupExecutionsRoute }}"')
        ->toContain('<a class="button" wire:navigate href="{{ $backupRoute }}">Manage</a>')
        ->and($component)->toContain("withCount('executions')");
});

it('returns from database backup settings to the database', function () {
    $sidebar = file_get_contents(resource_path('views/components/backup-sidebar.blade.php'));

    expect($sidebar)
        ->toContain("'back' => 'project.database.configuration'")
        ->toContain("'Back to database'")
        ->toContain("except('backup_uuid')");
});

it('shows the backup path directly on every execution', function () {
    $executions = file_get_contents(resource_path('views/livewire/project/database/backup-executions.blade.php'));

    expect($executions)
        ->toContain('Backup path:')
        ->toContain("data_get(\$execution, 'filename', 'N/A')")
        ->toContain('<span>Backup path</span>')
        ->toContain('class="select-all truncate font-mono text-[11px]')
        ->not->toContain('backup-executions-table-grid border-t');
});
