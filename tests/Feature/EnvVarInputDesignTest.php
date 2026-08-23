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

it('keeps the environment variable input enabled while secret manager keys load', function () {
    $view = file_get_contents(resource_path('views/components/forms/env-var-input.blade.php'));

    expect($view)->toContain('wire:target.except="fetchSecretManagerKeys"');
});

it('passes the remove source warning without compiling remote secret syntax as blade', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/secret-manager-links.blade.php'));

    expect($view)
        ->toContain('with {{vault.KEY}}. Values are fetched')
        ->not->toContain('{{doppler.KEY}}')
        ->not->toContain('{{infisical.KEY}}')
        ->toContain(':actions="[$removeSourceWarning]"')
        ->not->toContain(':actions="[\'Existing {{vault.*}}');
});

it('shows secret manager configuration for applications services and databases', function () {
    $views = [
        resource_path('views/livewire/project/application/configuration.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
        resource_path('views/livewire/project/database/configuration.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))->toContain('livewire:project.shared.secret-manager-links');
    }
});
