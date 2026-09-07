<?php

it('uses shared sidebar navigation for every notification channel', function () {
    $sidebar = file_get_contents(resource_path('views/components/notification/settings-layout.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    foreach (['email', 'discord', 'telegram', 'slack', 'pushover', 'webhook'] as $channel) {
        $view = file_get_contents(resource_path("views/livewire/notifications/{$channel}.blade.php"));

        expect($view)
            ->toContain('<x-notification.settings-layout>')
            ->not->toContain('<x-notification.navbar');
    }

    expect($sidebar)
        ->toContain('application-settings-navigation')
        ->toContain('Notification settings')
        ->toContain("'label' => 'Email'")
        ->toContain("'icon' => 'mail'")
        ->toContain("'label' => 'Discord'")
        ->toContain("'label' => 'Telegram'")
        ->toContain("'label' => 'Slack'")
        ->toContain("'label' => 'Pushover'")
        ->toContain("'label' => 'Webhook'");

    foreach (['discord', 'telegram', 'slack', 'pushover'] as $channel) {
        expect(public_path("svgs/{$channel}.svg"))->toBeFile();
    }

    expect(file_get_contents(public_path('svgs/pushover.svg')))
        ->toContain('<path')
        ->not->toContain('<ellipse');

    expect($sidebar)
        ->toContain("'brandIcon' => 'discord'")
        ->toContain("'brandIcon' => 'telegram'")
        ->toContain("'brandIcon' => 'slack'")
        ->toContain("'brandIcon' => 'pushover'")
        ->not->toContain("'color' =>");

    expect($navbar)
        ->toContain("request()->routeIs('notifications.*')")
        ->not->toContain("['label' => 'Discord', 'route' => 'notifications.discord'");
});

it('keeps telegram forum topics separate from event multiselects', function () {
    $grid = file_get_contents(resource_path('views/components/notification/event-grid.blade.php'));
    $telegram = file_get_contents(resource_path('views/livewire/notifications/telegram.blade.php'));

    expect($telegram)
        ->toContain('channel="telegram" threaded');

    expect($grid)
        ->toContain('title="Notification events"')
        ->toContain('title="Forum topics"')
        ->toContain('Enable one or more events above to assign forum topic IDs.')
        ->toContain('$enabledThreadEvents')
        ->not->toContain('label="{{ $event[\'label\'] }} thread ID"')
        ->not->toContain('border-l border-neutral-200 pl-3');
});

it('renders settings section descriptions in the header', function () {
    $section = file_get_contents(resource_path('views/components/application/settings-section.blade.php'));

    expect($section)
        ->toContain('filled($description)')
        ->toContain('{{ $description }}');
});

it('uses action buttons and browser validation for notification channel state', function () {
    $actions = file_get_contents(resource_path('views/components/notification/channel-actions.blade.php'));

    expect($actions)
        ->toContain('{{ $enabled ? \'Disable\' : \'Enable\' }}')
        ->toContain('reportValidity()')
        // @js() must live on plain HTML (x-data), not on <x-forms.button> attributes —
        // Blade leaves @js uncompiled inside component tag attributes, which breaks Alpine.
        ->toContain('enabled: @js((bool) $enabled)')
        ->toContain('enabledProperty: @js($enabledProperty)')
        ->toContain('toggleMethod: @js($toggleMethod)')
        ->toContain('testMethod: @js($testMethod)')
        ->toContain('$wire.$set(enabledProperty, !enabled)')
        ->toContain('$wire.$call(toggleMethod)')
        ->toContain('$wire.$call(testMethod)')
        ->not->toContain('$wire.$set(@js($enabledProperty)');

    foreach (['discord', 'telegram', 'slack', 'pushover', 'webhook'] as $channel) {
        $view = file_get_contents(resource_path("views/livewire/notifications/{$channel}.blade.php"));

        expect($view)
            ->toContain('<x-notification.channel-actions')
            ->not->toContain("id=\"{$channel}Enabled\"");
    }
});
