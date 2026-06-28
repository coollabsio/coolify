<div>
    <h2>Import Backup</h2>
    @if ($unsupported)
        <div>Database restore is not supported.</div>
    @elseif (str($resourceStatus)->startsWith('running'))
        <livewire:project.database.import-form wire:key="database-import-form-{{ $resourceUuid }}" />
    @else
        <div>Database must be running to restore a backup.</div>
    @endif
</div>
