@props([
    'parameters',
    'serviceDatabase',
])

<x-main-nav-menu :depth="1" back-label="Service" back-title="Back to the service menu">
    <nav aria-label="Compose resource settings"
        class="grid grid-cols-1 gap-0.5">
        <a class="menu-item" data-page-only {{ wireNavigate() }}
            href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
            <x-reicon name="logout" class="menu-item-icon rotate-180" />
            <span class="menu-item-label">Back to service</span>
        </a>

        <a class="menu-item menu-item-active" {{ wireNavigate() }}
            href="{{ route('project.service.index', $parameters) }}">
            <x-reicon name="settings" class="menu-item-icon" />
            <span class="menu-item-label">General</span>
        </a>
    </nav>
</x-main-nav-menu>
