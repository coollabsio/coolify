<?php

test('boarding utility actions are centered', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)->toContain('class="mx-auto mt-6 flex w-full max-w-3xl flex-col items-center gap-3"');
});

test('server validation opens in the centered process dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->toContain('<x-process-dialog closeWithX size="xl">')
        ->toContain('@click="processDialogOpen = true"')
        ->not->toContain('<x-slide-over closeWithX fullScreen>');
});

test('DigitalOcean is available as an onboarding server provider', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->toContain('<x-modal-input title="Connect a DigitalOcean Server" isFullWidth>')
        ->toContain('<x-digital-ocean-icon class="size-10 shrink-0" />')
        ->toContain('Deploy servers directly from your DigitalOcean account.')
        ->toContain('<livewire:server.new.by-digital-ocean :limit_reached="false" :from_onboarding="true" />');
});

test('server type details are shown on the relevant cards instead of a technical details panel', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->toContain('aria-label="About this machine"')
        ->toContain('aria-label="About remote servers"')
        ->toContain('Not recommended for production workloads due to resource contention.')
        ->toContain('Any SSH-accessible server, including cloud VPS, bare metal, and self-hosted infrastructure.')
        ->not->toContain('<x-highlighted text="Servers" />')
        ->not->toContain('<x-highlighted text="Localhost:" />')
        ->not->toContain('<x-highlighted text="Remote Server:" />');
});

test('server type cards use the standard card hover treatment', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));
    $hoverClasses = 'shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md';
    preg_match_all('/min-h-36[^\"]*'.preg_quote($hoverClasses, '/').'/', $view, $matches);

    expect($matches[0])->toHaveCount(5)
        ->and(substr_count($view, 'group relative cursor-pointer'))->toBe(5)
        ->and($view)->not->toContain('hover:border-coollabs/35 hover:bg-coollabs/[0.03]')
        ->and($view)->not->toContain('dark:hover:border-warning/25 dark:hover:bg-warning/[0.04]');
});

test('existing SSH key selection does not show a redundant value tooltip', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)->toContain('label="Existing SSH key" :options="$privateKeyOptions" :tooltip="false"');
});

test('server connection step does not render a technical details panel', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->not->toContain('<x-highlighted text="Connection Requirements:" />')
        ->not->toContain('<x-highlighted text="Hostname Resolution:" />')
        ->not->toContain('<x-highlighted text="User Permissions:" />');
});

test('project step does not render a technical details panel', function () {
    $view = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($view)
        ->not->toContain('<x-highlighted text="Project Organization:" />')
        ->not->toContain('<x-highlighted text="Environments:" />')
        ->not->toContain('<x-highlighted text="Team Access:" />');
});
