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

test('sentinel custom docker image x-init only sets wire when a value exists', function () {
    $path = resource_path('views/livewire/server/sentinel.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain("x-init=\"if (customImage) { \$wire.set('sentinelCustomDockerImage', customImage) }\"")
        ->not->toContain("x-init=\"\$wire.set('sentinelCustomDockerImage', customImage)\"");
});
