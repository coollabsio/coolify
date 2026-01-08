@props([
    'parameters',
    'serviceDatabase',
    'isImportSupported' => false,
])

<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item"
        class="{{ request()->routeIs('project.service.configuration') ? 'menu-item-active' : '' }}"
        {{ wireNavigate() }}
        href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
        <button><- Back</button>
    </a>
    <a class="menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
        href="{{ route('project.service.index', $parameters) }}">General</a>
    @if ($serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated)
        <a class="menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
            href="{{ route('project.service.database.backups', $parameters) }}">Backups</a>
    @endif
    @if ($isImportSupported)
        <a class="menu-item" wire:current.exact="menu-item-active"
            href="{{ route('project.service.database.import', $parameters) }}">Import Backup</a>
    @endif
</div>
