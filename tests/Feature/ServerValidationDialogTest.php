<?php

test('server revalidation opens in the centered process dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($view)
        ->toContain('<x-process-dialog closeWithX size="xl" :open="$isValidating">')
        ->toContain(':isHighlighted="! $server->isFunctional()"')
        ->toContain('@click="processDialogOpen = true" wire:click.prevent="validateServer"')
        ->not->toContain('<x-slide-over');
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

    expect($view)->toContain('@elseif ($isInstalling)')
        ->and($component)
        ->toContain('public bool $isInstalling = false;')
        ->toContain('$this->isInstalling = true;');
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

    expect($view)
        ->toContain('data-validation-checkpoints')
        ->toContain('overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]');
});

test('all validation checkpoints remain visible while only the current phase runs', function () {
    $view = file_get_contents(resource_path('views/livewire/server/validate-and-install.blade.php'));

    expect($view)->not->toContain("@continue(! \$checkpoint['visible'])");
});
