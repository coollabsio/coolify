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

it('allows secret manager key loading to retry after a failed request', function () {
    $view = file_get_contents(resource_path('views/components/forms/env-var-input.blade.php'));
    $failureHandler = explode('});', explode('.catch(() => {', $view, 2)[1], 2)[0];

    expect($failureHandler)
        ->toContain('this.vaultKeysLoading = false;')
        ->not->toContain("this.availableVars['vault'] = [];");
});

it('authorizes secret-enabled environment variable inputs at the component boundary', function () {
    $addView = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/add.blade.php'));
    $showView = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    foreach ([$addView, $showView] as $view) {
        preg_match('/<x-forms\.env-var-input[\s\S]*?\/>/', $view, $matches);

        expect($matches[0] ?? '')
            ->toContain('canGate="manageEnvironment"')
            ->toContain(':canResource="$resource"');
    }
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
