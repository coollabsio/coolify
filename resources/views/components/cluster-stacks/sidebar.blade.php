@props(['section', 'routeParameters', 'stack', 'navOnly' => false])

@php
    $canAccessTerminal = auth()->user()?->can('canAccessTerminal') ?? false;

    // The v4 service menu. An item without a `key` is a v4 page that stacks do not have yet: it
    // stays in the menu, disabled, so both menus keep the same shape.
    $menuGroups = [
        'Settings' => [
            ['key' => 'general', 'label' => 'General', 'icon' => 'settings'],
            ['key' => 'domains', 'label' => 'Domains', 'icon' => 'globe'],
            ['key' => 'environment-variables', 'label' => 'Environment Variables', 'icon' => 'variables'],
            ['key' => 'storages', 'label' => 'Persistent Storage', 'icon' => 'storages'],
        ],
        'Observe & troubleshoot' => [
            ['key' => 'logs', 'label' => 'Runtime Logs', 'icon' => 'unordered-list'],
            // Not in the v4 service menu: the deploys of the stack (node_stack_deployments).
            ['key' => 'deployments', 'label' => 'Deployment Logs', 'icon' => 'time-back'],
            ['key' => 'terminal', 'label' => 'Terminal', 'icon' => 'browser-terminal', 'visible' => $canAccessTerminal],
            ['label' => 'Files', 'icon' => 'folder', 'visible' => $canAccessTerminal],
            ['label' => 'Analytics', 'icon' => 'analytics'],
            ['label' => 'Security', 'icon' => 'shield-alert'],
        ],
        'Automation' => [
            ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'icon' => 'calendar'],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'icon' => 'webhook'],
            ['key' => 'backups', 'label' => 'Backups', 'icon' => 'database'],
        ],
        'Operations' => [
            ['label' => 'Resource Operations', 'icon' => 'server-update'],
            ['label' => 'Maintenance Mode', 'icon' => 'bandage'],
            ['label' => 'Tags', 'icon' => 'tags'],
        ],
        'Danger Zone' => [
            ['key' => 'danger', 'label' => 'Danger Zone', 'icon' => 'danger', 'visible' => auth()->user()?->can('delete', $stack) ?? false],
        ],
    ];
    $routes = [
        'general' => 'project.cluster-stack.show',
        'domains' => 'project.cluster-stack.domains',
        'environment-variables' => 'project.cluster-stack.environment-variables',
        'storages' => 'project.cluster-stack.storages',
        'logs' => 'project.cluster-stack.logs',
        'deployments' => 'project.cluster-stack.deployments',
        'terminal' => 'project.cluster-stack.terminal',
        'scheduled-tasks' => 'project.cluster-stack.scheduled-tasks',
        'webhooks' => 'project.cluster-stack.webhooks',
        'backups' => 'project.cluster-stack.backups',
        'danger' => 'project.cluster-stack.danger',
    ];
    $groupedMenuItems = collect($menuGroups)
        ->map(fn (array $items) => collect($items)->filter(fn (array $item): bool => $item['visible'] ?? true)->values())
        ->filter(fn ($items) => $items->isNotEmpty());
@endphp

<x-main-nav-menu back-label="Dashboard" :name="$stack->name" :nav-only="$navOnly">
    <nav aria-label="Cluster stack sections" class="grid grid-cols-1 gap-0.5">
        @foreach ($groupedMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                @php $key = $menuItem['key'] ?? null; @endphp
                @if ($key === null)
                    <span class="menu-item cursor-not-allowed opacity-50" aria-disabled="true" title="Not available on clusters yet">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                        @if ($menuItem['label'] === 'Files')
                            <x-beta-badge class="ml-auto shrink-0" />
                        @endif
                    </span>
                @else
                    <a @class(['menu-item', 'menu-item-active' => $key === $section])
                        @if ($key === $section) aria-current="page" @endif
                        {{ wireNavigate() }} href="{{ route($routes[$key], $routeParameters) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                @endif
            @endforeach
        @endforeach
    </nav>
</x-main-nav-menu>
