<div class="application-settings-form">
    @if ($unsupported)
        <x-application.settings-section title="Restore database"
            description="Import a backup into this database.">
            <x-empty title="Restore is not supported"
                description="This database type does not currently support backup imports." size="sm">
                <x-slot:icon>
                    <x-reicon name="database" class="size-6" />
                </x-slot:icon>
            </x-empty>
        </x-application.settings-section>
    @elseif (str($resourceStatus)->startsWith('running'))
        <livewire:project.database.import-form wire:key="database-import-form-{{ $resourceUuid }}" />
    @else
        <x-application.settings-section title="Restore database"
            description="Import a backup into this database.">
            <x-empty title="Start the database first"
                description="The database must be running before Coolify can restore a backup." size="sm">
                <x-slot:icon>
                    <x-reicon name="database" class="size-6" />
                </x-slot:icon>
            </x-empty>
        </x-application.settings-section>
    @endif
</div>
