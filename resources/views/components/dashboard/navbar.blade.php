@props([
    'section',
    'parameters' => [],
])

@php
    $items = match ($section) {
        'shared-variables' => [
            ['label' => 'Overview', 'route' => 'shared-variables.index', 'active' => request()->routeIs('shared-variables.index')],
            ['label' => 'Team', 'route' => 'shared-variables.team.index', 'active' => request()->routeIs('shared-variables.team.*')],
            ['label' => 'Projects', 'route' => 'shared-variables.project.index', 'active' => request()->routeIs('shared-variables.project.*')],
            ['label' => 'Environments', 'route' => 'shared-variables.environment.index', 'active' => request()->routeIs('shared-variables.environment.*')],
            ['label' => 'Servers', 'route' => 'shared-variables.server.index', 'active' => request()->routeIs('shared-variables.server.*')],
        ],
        'team' => [
            ['label' => 'General', 'route' => 'team.index', 'active' => request()->routeIs('team.index')],
            ['label' => 'Members', 'route' => 'team.member.index', 'active' => request()->routeIs('team.member.index')],
            [
                'label' => 'Admin View',
                'route' => 'team.admin-view',
                'active' => request()->routeIs('team.admin-view'),
                'visible' => isInstanceAdmin(),
            ],
        ],
        'profile' => [
            ['label' => 'General', 'route' => 'profile', 'active' => request()->routeIs('profile')],
            ['label' => 'Appearance', 'route' => 'profile.appearance', 'active' => request()->routeIs('profile.appearance')],
        ],
        'notifications' => [
            ['label' => 'Email', 'route' => 'notifications.email', 'active' => request()->routeIs('notifications.email')],
            ['label' => 'Discord', 'route' => 'notifications.discord', 'active' => request()->routeIs('notifications.discord')],
            ['label' => 'Telegram', 'route' => 'notifications.telegram', 'active' => request()->routeIs('notifications.telegram')],
            ['label' => 'Slack', 'route' => 'notifications.slack', 'active' => request()->routeIs('notifications.slack')],
            ['label' => 'Pushover', 'route' => 'notifications.pushover', 'active' => request()->routeIs('notifications.pushover')],
            ['label' => 'Webhook', 'route' => 'notifications.webhook', 'active' => request()->routeIs('notifications.webhook')],
        ],
        'security' => [
            ['label' => 'Private Keys', 'route' => 'security.private-key.index', 'active' => request()->routeIs('security.private-key.*')],
            [
                'label' => 'Cloud Tokens',
                'route' => 'security.cloud-tokens',
                'active' => request()->routeIs('security.cloud-tokens*'),
                'visible' => auth()->user()?->can('viewAny', App\Models\CloudProviderToken::class),
            ],
            [
                'label' => 'Cloud-Init Scripts',
                'route' => 'security.cloud-init-scripts',
                'active' => request()->routeIs('security.cloud-init-scripts*'),
                'visible' => auth()->user()?->can('viewAny', App\Models\CloudInitScript::class),
            ],
            ['label' => 'API Tokens', 'route' => 'security.api-tokens', 'active' => request()->routeIs('security.api-tokens')],
        ],
        'settings' => [
            [
                'label' => 'Configuration',
                'route' => 'settings.index',
                'active' => request()->routeIs('settings.index', 'settings.advanced', 'settings.updates'),
            ],
            ['label' => 'Backup', 'route' => 'settings.backup', 'active' => request()->routeIs('settings.backup')],
            ['label' => 'Transactional Email', 'route' => 'settings.email', 'active' => request()->routeIs('settings.email')],
            ['label' => 'OAuth', 'route' => 'settings.oauth', 'active' => request()->routeIs('settings.oauth')],
            ['label' => 'Scheduled Jobs', 'route' => 'settings.scheduled-jobs', 'active' => request()->routeIs('settings.scheduled-jobs')],
        ],
        'source' => [
            ['label' => 'General', 'route' => 'source.github.show', 'active' => request()->routeIs('source.github.show')],
            ['label' => 'Permissions', 'route' => 'source.github.permissions', 'active' => request()->routeIs('source.github.permissions')],
            ['label' => 'Resources', 'route' => 'source.github.resources', 'active' => request()->routeIs('source.github.resources')],
        ],
        'destination' => [
            ['label' => 'General', 'route' => 'destination.show', 'active' => request()->routeIs('destination.show')],
            ['label' => 'Resources', 'route' => 'destination.resources', 'active' => request()->routeIs('destination.resources')],
        ],
        'storage' => [
            ['label' => 'General', 'route' => 'storage.show', 'active' => request()->routeIs('storage.show')],
            ['label' => 'Resources', 'route' => 'storage.resources', 'active' => request()->routeIs('storage.resources')],
        ],
        'subscription' => [
            ['label' => 'Plan', 'route' => 'subscription.show', 'active' => request()->routeIs('subscription.show'), 'icon' => 'subscription'],
            ['label' => 'Pricing', 'route' => 'subscription.index', 'active' => request()->routeIs('subscription.index'), 'icon' => 'dashboard'],
        ],
        default => [],
    };

    $items = array_values(array_filter($items, fn (array $item): bool => $item['visible'] ?? true));
@endphp

<nav class="mb-6 w-full lg:mb-0">
    <div class="w-full lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
        :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
        <div class="flex w-full items-center justify-between gap-4 lg:h-full">
            <div
                class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($items as $item)
                    <a @class([
                        'app-tab shrink-0',
                        'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $item['active'],
                    ])
                        {{ wireNavigate() }} href="{{ route($item['route'], $parameters) }}">
                        @if ($item['icon'] ?? null)
                            <x-reicon :name="$item['icon']" class="size-3.5" />
                        @endif
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    </div>

    <div class="hidden lg:block lg:h-10" aria-hidden="true"></div>
</nav>
