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
