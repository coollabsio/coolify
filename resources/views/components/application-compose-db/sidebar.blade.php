@props([
    'parameters',
    'serviceDatabase',
    'isImportSupported' => false,
])

<div class="sub-menu-wrapper">
    <a class="sub-menu-item"
        class="{{ request()->routeIs('project.application.configuration') ? 'menu-item-active' : '' }}"
        {{ wireNavigate() }}
        href="{{ route('project.application.configuration', $parameters) }}">
        <svg xmlns="http://www.w3.org/2000/svg" class="sub-menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        <span class="menu-item-label">Back</span>
    </a>
    @if ($serviceDatabase?->isBackupSolutionAvailable())
        <a class="sub-menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
            href="{{ route('project.application.compose-db.backups', $parameters) }}"><span class="menu-item-label">Backups</span></a>
    @endif
</div>
