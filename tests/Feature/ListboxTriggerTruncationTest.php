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

test('listbox trigger height matches shared inputs', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\.listbox-trigger \{[^}]*height: 2\.25rem;/s')
        ->toMatch('/\.application-settings-workspace \.listbox-trigger[^}]*height: 2rem;/s');
});

test('disabled listboxes use the same colors as disabled inputs', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\.listbox-trigger:disabled \{[^}]*background-color: var\(--color-neutral-100\);[^}]*color: var\(--color-neutral-400\);/s')
        ->toMatch('/\.dark \.listbox-trigger:disabled \{[^}]*background-color: color-mix\(in oklab, var\(--color-white\) 3%, transparent\);[^}]*color: var\(--color-fg-faint\);/s')
        ->not->toMatch('/\.listbox-trigger:disabled \{[^}]*opacity:/s');
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

test('listboxes stay in their local Alpine scope by default', function () {
    $component = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));
    $html = Blade::render('<x-forms.listbox id="region" :options="[]" :wire="false" />');

    expect($component)->toContain("'portal' => false")
        ->and($html)
        ->toContain('x-data="{')
        ->toContain('x-ref="panel"')
        ->not->toContain('x-teleport="body"')
        ->not->toContain('floatingDropdown(')
        ->not->toContain('style="position: fixed');
});

test('mobile listbox panels stay anchored to their trigger', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->not->toContain('.listbox-panel:not([style*="position: fixed"])')
        ->not->toContain('transform: translate(-50%, -50%) !important;');
});

test('portaled listboxes measure content without inheriting the viewport width', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));

    expect($listbox)
        ->toContain("panel.style.width = 'max-content';")
        ->toContain('panel.style.minWidth = `${triggerRect.width}px`;')
        ->toContain('Math.max(triggerRect.width, panel.offsetWidth)');
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

test('listbox shows an empty state when it has no options', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));
    $operations = file_get_contents(resource_path('views/livewire/project/shared/resource-operations.blade.php'));

    expect($listbox)
        ->toContain("'emptyText' => 'No options available.'")
        ->toContain('x-show="options.length === 0"');

    expect($operations)->toContain('No network destinations are available on this server.');
});

test('listbox forwards dynamic disabled state to its trigger', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));
    $operations = file_get_contents(resource_path('views/livewire/project/shared/resource-operations.blade.php'));

    expect($listbox)->toContain("\$attributes->whereStartsWith('x-bind:disabled')");

    expect($operations)
        ->toContain('selectedCloneServer: null')
        ->toContain('selectedCloneProject: null')
        ->toContain('selectedCloneEnvironment: null')
        ->toContain('currentServerId: @js($resource->destination->server->id)')
        ->toContain('currentDestinationUuid: @js($resource->destination->uuid)')
        ->toContain("server.id == this.currentServerId ? ' (current)' : ''")
        ->toContain("destination.uuid == this.currentDestinationUuid ? ' (current)' : ''")
        ->toContain('selectedCloneServer = null;')
        ->toContain('placeholder="Choose a server…"')
        ->toContain("this.selectedCloneServer === null || this.selectedCloneServer === ''")
        ->toContain("x-bind:disabled=\"selectedCloneServer === null || selectedCloneServer === ''\"")
        ->toContain('x-model="selectedCloneProject"')
        ->toContain('x-model="selectedCloneEnvironment"')
        ->toContain('$wire.cloneTo(selectedCloneDestination)')
        ->toContain('$wire.cloneTo(currentDestinationUuid, selectedCloneEnvironment)')
        ->not->toContain('$wire.cloneTo(@js(')
        ->toContain('x-bind:disabled="!selectedMoveProject || availableEnvironments.length === 0"');
});

test('listbox waits for change handlers and prevents overlapping selections', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));

    expect($listbox)
        ->toContain('saving: false')
        ->toContain('async choose(option)')
        ->toContain('await this.$wire.')
        ->toContain('if (this.saving || option.disabled) return;')
        ->toContain("'pointer-events-none opacity-70': saving");
});

test('listbox does not send a second live entangle request when using a change handler', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));

    expect($listbox)->toContain('@elseif ($live && ! $onChange) @entangle($id).live');
});

test('listbox can preserve its client value across Livewire morphs', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/listbox.blade.php'));

    expect($listbox)
        ->toContain("'preserveValue' => false")
        ->toContain('@if ($preserveValue) wire:ignore @endif');
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

test('notification event multiselect uses the same panel animation as listboxes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-notification.event-multiselect id="deployment-email-events" label="Deployments" :events="[
            ['property' => 'deploymentSuccessEmailNotifications', 'label' => 'Deployment success', 'enabled' => false],
            ['property' => 'deploymentFailureEmailNotifications', 'label' => 'Deployment failure', 'enabled' => true],
            ['property' => 'statusChangeEmailNotifications', 'label' => 'Container status changes', 'enabled' => false],
        ]" />
    BLADE);

    expect($html)
        ->toContain('x-transition:enter="transition ease-out duration-100"')
        ->toContain('x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"')
        ->toContain('x-transition:enter-end="opacity-100 translate-y-0 scale-100"')
        ->toContain('x-transition:leave="transition ease-in duration-75"')
        ->toContain('x-transition:leave-start="opacity-100 translate-y-0 scale-100"')
        ->toContain('x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]"');
});
