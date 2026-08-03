<?php

use Illuminate\Support\Facades\Blade;

test('listbox trigger styles constrain width and ellipsize long labels', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.listbox-trigger {')
        ->toContain('min-width: 0;')
        ->toContain('overflow: hidden;')
        ->toContain('.listbox-trigger-label {')
        ->toContain('text-overflow: ellipsis;')
        ->toContain('white-space: nowrap;');
});

test('listbox component uses shared trigger label truncation', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="longOption" label="Example"
            :options="[
                ['value' => 'long', 'label' => 'A very long option label that should truncate'],
            ]" :wire="false" value="long" />
    BLADE);

    expect($html)
        ->toContain('class="w-full min-w-0"')
        ->toContain('relative min-w-0')
        ->toContain('listbox-trigger')
        ->toContain('listbox-trigger-label')
        ->toContain(':title="current"')
        ->not->toContain('class="truncate" x-text="current"');
});

test('searchable listbox component uses shared trigger label truncation', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.searchable-listbox id="tz" label="Timezone"
            :options="[['value' => 'UTC', 'label' => 'UTC']]" :wire="false" value="UTC" />
    BLADE);

    expect($html)
        ->toContain('class="w-full min-w-0"')
        ->toContain('relative min-w-0')
        ->toContain('listbox-trigger-label')
        ->toContain(':title="current"');
});

test('notification event multiselect truncates long selected summaries', function () {
    $html = Blade::render(<<<'BLADE'
        <x-notification.event-multiselect id="server-slack-events" label="Servers" :events="[
            ['property' => 'dockerCleanupFailureSlackNotifications', 'label' => 'Docker cleanup failure', 'enabled' => true],
            ['property' => 'serverDiskUsageSlackNotifications', 'label' => 'Disk usage warning', 'enabled' => true],
            ['property' => 'serverUnreachableSlackNotifications', 'label' => 'Server unreachable', 'enabled' => true],
            ['property' => 'serverPatchSlackNotifications', 'label' => 'Server patching', 'enabled' => true],
        ]" />
    BLADE);

    expect($html)
        ->toContain('class="w-full min-w-0"')
        ->toContain('relative min-w-0')
        ->toContain('listbox-trigger')
        ->toContain('listbox-trigger-label')
        ->toContain('Docker cleanup failure, Disk usage warning, Server unreachable, Server patching')
        ->toContain('title="Docker cleanup failure, Disk usage warning, Server unreachable, Server patching"')
        ->toContain('4/4');
});
