@props([
    'parameters',
    'serviceDatabase',
    'isImportSupported' => false,
])

<div class="sub-menu-wrapper">
    <a class="sub-menu-item"
        class="{{ request()->routeIs('project.service.configuration') ? 'menu-item-active' : '' }}"
        {{ wireNavigate() }}
        href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
        <svg xmlns="http://www.w3.org/2000/svg" class="sub-menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        <span class="menu-item-label">Back</span>
    </a>
    <a class="sub-menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
        href="{{ route('project.service.index', $parameters) }}"><span class="menu-item-label">General</span></a>
    @if ($serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated)
        <a class="sub-menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
            href="{{ route('project.service.database.backups', $parameters) }}"><span class="menu-item-label">Backups</span></a>
    @endif
    @if ($isImportSupported)
        <a class="sub-menu-item" wire:current.exact="menu-item-active"
            href="{{ route('project.service.database.import', $parameters) }}"><span class="menu-item-label">Import Backup</span></a>
    @endif
</div>
