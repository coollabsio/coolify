<?php

test('toast messages default to the bottom right while supporting position overrides', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));

    expect($toast)
        ->toContain("position: options.position ?? 'bottom-right'")
        ->toContain("position: 'bottom-right'")
        ->toContain("this.position = event.detail.position || 'bottom-right'")
        ->toContain("'right-4 bottom-4 flex-col-reverse': position === 'bottom-right'")
        ->toContain("'left-1/2 top-4 -translate-x-1/2 flex-col': position === 'top-center'");
});

test('toast copy button shows temporary success feedback', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));

    expect($toast)
        ->toContain('copied: false')
        ->toContain('copyToast(toast)')
        ->toContain('toast.copied = true')
        ->toContain('toast.copied = false')
        ->toContain('}, 2000)')
        ->toContain('x-show="!toast.copied"')
        ->toContain('x-show="toast.copied"')
        ->toContain("'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400': toast.copied");
});
