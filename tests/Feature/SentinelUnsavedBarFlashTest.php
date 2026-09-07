<?php

/**
 * The Sentinel page previously flashed the floating unsaved bar on open because:
 * 1) wire:dirty compared the entire Livewire snapshot, and
 * 2) dev-only x-init always called $wire.set('sentinelCustomDockerImage', …),
 *    mutating reactive state (null → '') until the round-trip cleared dirty.
 */
test('sentinel unsaved bar scopes dirty tracking to savable form fields', function () {
    $path = resource_path('views/livewire/server/sentinel.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('x-unsaved-bar')
        ->toContain('targets="sentinelCustomUrl,sentinelToken,sentinelMetricsRefreshRateSeconds,sentinelMetricsHistoryDays,sentinelPushIntervalSeconds"')
        ->not->toMatch('/x-unsaved-bar\s+action="submit"\s*\/>/');
});

test('unsaved bar component accepts optional wire:target list', function () {
    $path = resource_path('views/components/unsaved-bar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain("'targets' => null")
        ->toContain('wire:target="{{ $targets }}"');
});

test('unsaved bar delays show and hides while loading to avoid instant-save flash', function () {
    $path = resource_path('views/components/unsaved-bar.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('wire:dirty.class="is-dirty"')
        ->toContain('wire:loading.class="is-saving"')
        ->toContain('[&.is-dirty]:delay-300')
        ->toContain('delay-0');
});

test('unsaved bar uses a stable transition for its entrance', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('scale-95')
        ->toContain('transition-[opacity,transform,scale]')
        ->toContain('duration-300')
        ->toContain('ease-[cubic-bezier(0.16,1,0.3,1)]')
        ->toContain('[&.is-dirty]:scale-100')
        ->not->toContain('[&.is-dirty]:animate-in');
});

test('unsaved bar transitions out without restarting its entrance animation', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('[&.is-saving]:translate-y-6')
        ->toContain('[&.is-saving]:scale-95')
        ->toContain('[&.is-saving]:opacity-0')
        ->toContain('[&.is-saving]:duration-200')
        ->toContain('[&.is-saving]:ease-in')
        ->toContain('[&.is-saving]:pointer-events-none')
        ->not->toContain('[&.is-saving]:animate-out');
});

test('unsaved bar saves with enter and shows the shortcut on the save button', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('@keydown.enter.window')
        ->toContain("classList.contains('is-dirty')")
        ->toContain('$wire.{{ $action }}()')
        ->toContain('<kbd')
        ->toContain('Enter</kbd>');
});

test('unsaved bar keyboard shortcut remains visible in light mode', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('border-current/20 bg-current/10')
        ->toContain('text-current')
        ->not->toContain('text-coollabs-200');
});

test('unsaved bar uses a light surface in light mode', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('border-neutral-200 bg-white')
        ->toContain('text-neutral-800 dark:text-fg')
        ->toContain('bg-neutral-100')
        ->toContain('dark:bg-white/[0.07]');
});

test('unsaved bar stays above floating notifications so save actions remain accessible', function () {
    $unsavedBar = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));
    $popup = file_get_contents(resource_path('views/components/popup-small.blade.php'));

    expect($unsavedBar)
        ->toContain('z-[1000]')
        ->and($popup)->toContain('z-999');
});

test('unsaved bar stays above the mobile keyboard', function () {
    $contents = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));

    expect($contents)
        ->toContain('window.visualViewport')
        ->toContain('keyboardInset')
        ->toContain('--keyboard-inset')
        ->toContain('removeEventListener');
});

test('settings general unsaved bar excludes auto-saving instance timezone', function () {
    $path = resource_path('views/livewire/settings/index.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('targets="fqdn,instance_name,public_ipv4,public_ipv6,dev_helper_version"')
        ->toContain('id="instance_timezone"')
        ->toContain('onChange="submit"')
        ->toContain('x-forms.searchable-listbox')
        ->not->toMatch('/x-unsaved-bar\s+action="submit"\s*\/>/');
});

test('settings general instance timezone uses searchable listbox for label alignment with name', function () {
    $path = resource_path('views/livewire/settings/index.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('x-forms.searchable-listbox id="instance_timezone"')
        ->toContain('id="instance_name"')
        ->not->toContain('selectTimezone(timezone)');
});

test('sentinel custom docker image x-init only sets wire when a value exists', function () {
    $path = resource_path('views/livewire/server/sentinel.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain("x-init=\"if (customImage) { \$wire.set('sentinelCustomDockerImage', customImage) }\"")
        ->not->toContain("x-init=\"\$wire.set('sentinelCustomDockerImage', customImage)\"");
});

/**
 * Instant-save listboxes (e.g. MCP server) entangle + call instantSave. Until the
 * round-trip finishes, the component is dirty — so an unscoped unsaved bar flashes.
 * Pages that mix instantSave and explicit-save fields must pass targets.
 */
test('settings advanced unsaved bar scopes dirty tracking away from instantSave fields', function () {
    $path = resource_path('views/livewire/settings/advanced.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('x-unsaved-bar')
        ->toContain('targets="custom_dns_servers,allowed_ips,webhook_allowed_internal_hosts,webhook_allow_localhost,domain_connect_private_key"')
        ->toContain('onChange="instantSave"')
        ->not->toMatch('/x-unsaved-bar\s+action="submit"\s*\/>/');
});

test('settings updates unsaved bar scopes dirty tracking away from instantSave fields', function () {
    $path = resource_path('views/livewire/settings/updates.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('targets="update_check_frequency,auto_update_frequency"')
        ->not->toMatch('/x-unsaved-bar\s+action="submit"\s*\/>/');
});

test('docker cleanup unsaved bar scopes dirty tracking away from instantSave fields', function () {
    $path = resource_path('views/livewire/server/docker-cleanup.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('targets="dockerCleanupFrequency,dockerCleanupThreshold"')
        ->not->toMatch('/x-unsaved-bar\s+action="submit"\s*\/>/');
});

test('application advanced gpu unsaved bar scopes dirty tracking to gpu fields only', function () {
    $path = resource_path('views/livewire/project/application/advanced.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('targets="gpuDriver,gpuCount,gpuDeviceIds,gpuOptions"');
});
