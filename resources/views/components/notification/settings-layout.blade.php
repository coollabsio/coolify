@php
    $notificationMenuItems = [
        ['label' => 'Email', 'route' => 'notifications.email', 'icon' => 'mail'],
        ['label' => 'Discord', 'route' => 'notifications.discord', 'brandIcon' => 'discord'],
        ['label' => 'Telegram', 'route' => 'notifications.telegram', 'brandIcon' => 'telegram'],
        ['label' => 'Slack', 'route' => 'notifications.slack', 'brandIcon' => 'slack'],
        ['label' => 'Pushover', 'route' => 'notifications.pushover', 'brandIcon' => 'pushover'],
        ['label' => 'Webhook', 'route' => 'notifications.webhook', 'icon' => 'destinations'],
    ];
@endphp

{{-- These pages are in the Notifications submenu of the main sidebar (components/navbar.blade.php). --}}
<section class="w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Notifications</h1>
        <p class="settings-mobile-description">Configure how your team receives deployment and system alerts.</p>
    </header>
    {{-- Feeds the page search with the Notifications pages. --}}
    <div hidden data-settings-search-items="{{ json_encode(settingsSearchItems(['Notifications' => $notificationMenuItems], [])) }}"></div>
    <div class="min-w-0">
        {{ $slot }}
    </div>
</section>
