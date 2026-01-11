@props([
    'parameters',
    'serviceDatabase',
    'isImportSupported' => false,
])

<div class="sub-menu-wrapper">
    <a class="menu-item"
        class="{{ request()->routeIs('project.service.configuration') ? 'menu-item-active' : '' }}"
        {{ wireNavigate() }}
        href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
        <span class="menu-item-label"><- Back</span>
    </a>
    <a class="menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
        href="{{ route('project.service.index', $parameters) }}"><span class="menu-item-label">General</span></a>
    @if ($serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated)
        <a class="menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
            href="{{ route('project.service.database.backups', $parameters) }}"><span class="menu-item-label">Backups</span></a>
    @endif
    @if ($isImportSupported)
        <a class="menu-item" wire:current.exact="menu-item-active"
            href="{{ route('project.service.database.import', $parameters) }}"><span class="menu-item-label">Import Backup</span></a>
    @endif
</div>
