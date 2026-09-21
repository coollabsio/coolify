@props([
    'parameters',
    'serviceDatabase',
])

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Compose resource settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Compose resource</div>
        <a class="menu-item" {{ wireNavigate() }}
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
</aside>
