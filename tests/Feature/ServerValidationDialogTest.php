<?php

test('server revalidation opens in the centered process dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($view)
        ->toContain('<x-process-dialog closeWithX mobileFullscreen size="xl" :open="$isValidating">')
        ->toContain(':isHighlighted="! $server->isFunctional()"')
        ->toContain('@click="processDialogOpen = true" wire:click.prevent="validateServer"')
        ->not->toContain('<x-slide-over');
});

test('server revalidation uses the full mobile viewport', function () {
    $view = file_get_contents(resource_path('views/livewire/server/show.blade.php'));
    $dialog = file_get_contents(resource_path('views/components/process-dialog.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('<x-process-dialog closeWithX mobileFullscreen size="xl" :open="$isValidating">')
        ->and($dialog)
        ->toContain("'mobileFullscreen' => false")
        ->toContain("'process-dialog-mobile-fullscreen' => \$mobileFullscreen")
        ->and($styles)
        ->toContain('@media (max-width: 639px)')
        ->toContain('.process-dialog-mobile-fullscreen')
        ->toContain('height: 100dvh !important')
        ->toContain('box-shadow: inset 0 0 0 1px var(--coollabs-hairline) !important');
});

test('completed server validation shows a close action instead of empty logs', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));

    expect($view)
        ->toContain('$validationComplete')
        ->toContain('mt-auto')
        ->toContain('<x-forms.button type="button" @click="processDialogOpen = false">')
        ->toContain('@click="processDialogOpen = false"')
        ->toContain('Validation complete')
        ->toContain('Close');
});

test('installation logs are only shown after an installation starts', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));
    $component = file_get_contents(app_path('Livewire/Server/ValidateAndInstall.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('@elseif ($isInstalling)')
        ->toContain('application-settings-section validation-installation-logs')
        ->and($component)
        ->toContain('public bool $isInstalling = false;')
        ->toContain('$this->isInstalling = true;')
        ->and($styles)
        ->toContain('.validation-installation-logs')
        ->toContain('border: 1px solid var(--coollabs-fill)');
});

test('server validation content scrolls within the dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));
    $activityMonitor = file_get_contents(resource_path('views/livewire/activity-monitor.blade.php'));

    expect($view)->toContain('class="flex h-full min-h-0 flex-col gap-4 overflow-y-auto scrollbar"')
        ->and($activityMonitor)->toContain("'overflow-hidden' => !\$fullHeight")
        ->and($activityMonitor)->not->toContain("'h-full overflow-hidden' => !\$fullHeight");
});

test('validation checkpoints use the standard bordered list treatment', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('data-validation-checkpoints')
        ->toContain('shrink-0 overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]')
        ->toContain('checkpoint-scroll-fade')
        ->toContain('snap-x snap-mandatory overflow-x-auto overscroll-x-contain scroll-smooth scrollbar')
        ->toContain('data-checkpoint-status="{{ $checkpoint[\'status\'] }}"')
        ->toContain('basis-[88%] shrink-0 snap-start sm:basis-72 lg:basis-80')
        ->toContain("querySelector('[data-checkpoint-status=running]')")
        ->toContain("scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' })")
        ->toContain('new MutationObserver')
        ->toContain("attributeFilter: ['data-checkpoint-status']")
        ->toContain('x-destroy="observer?.disconnect()"')
        ->and($styles)
        ->toContain('.checkpoint-scroll-fade::after');
});

test('all validation checkpoints remain visible while only the current phase runs', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));

    expect($view)->not->toContain("@continue(! \$checkpoint['visible'])");
});
