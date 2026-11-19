<div>
    @if ($unsupported)
        <x-empty title="Restore is not supported"
            description="This database type does not currently support backup imports."
            icon-name="database" size="sm" />
    @elseif (str($resourceStatus)->startsWith('running'))
        <livewire:project.database.import-form :resource="$resource" wire:key="database-import-form-{{ $resourceUuid }}" />
    @else
        <x-empty title="Start the database first"
            description="The database must be running before Coolify can restore a backup."
            icon-name="database" size="sm" />
    @endif
</div>
