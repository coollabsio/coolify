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
        ->toContain('wire:loading.class="!opacity-0 !translate-y-6 !pointer-events-none"')
        ->toContain('[&.is-dirty]:delay-300')
        ->toContain('delay-0');
});

test('unsaved bar stays above floating notifications so save actions remain accessible', function () {
    $unsavedBar = file_get_contents(resource_path('views/components/unsaved-bar.blade.php'));
    $popup = file_get_contents(resource_path('views/components/popup-small.blade.php'));

    expect($unsavedBar)
        ->toContain('z-[1000]')
        ->and($popup)->toContain('z-999');
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
        ->toContain('targets="custom_dns_servers,allowed_ips,webhook_allowed_internal_hosts,webhook_allow_localhost"')
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
