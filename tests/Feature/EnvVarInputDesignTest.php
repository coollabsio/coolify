<?php

it('uses the current listbox design for environment variable suggestions', function () {
    $view = file_get_contents(resource_path('views/components/forms/env-var-input.blade.php'));

    expect($view)
        ->toContain('class="listbox-panel')
        ->toContain('class="listbox-option justify-start! gap-2.5!"')
        ->toContain("'bg-neutral-100 dark:bg-white/[0.08]': index === selectedIndex")
        ->toContain('border-warning/25 bg-warning/10')
        ->toContain('border-emerald-500/25 bg-emerald-500/10')
        ->not->toContain('dark:bg-coolgray-100');
});
