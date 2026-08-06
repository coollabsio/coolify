<?php

it('shows internal Docker access details in application general settings', function () {
    $generalSettings = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $generalComponent = file_get_contents(app_path('Livewire/Project/Application/General.php'));
    $internalAccessSettings = file_get_contents(resource_path('views/livewire/project/application/internal-access.blade.php'));
    $internalAccessComponent = file_get_contents(app_path('Livewire/Project/Application/InternalAccess.php'));
    $configurationSidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($generalSettings)
        ->toContain('id="access-section" title="Access"')
        ->toContain('<h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">Public access</h3>')
        ->toContain("Str::plural('domain', \$domainCount)")
        ->toContain('Domains, DNS checks, and redirect settings')
        ->toContain('class="flex items-center gap-3 rounded-lg')
        ->toContain('class="icon-button ml-auto shrink-0"')
        ->toContain('aria-label="Manage domains"')
        ->toContain('<x-reicon name="settings" class="size-4" />')
        ->not->toContain('<x-reicon name="arrow-right" class="size-3.5" />')
        ->toContain('<livewire:project.application.internal-access')
        ->not->toContain('wire:init="loadCurrentInternalHostname"')
        ->and($internalAccessSettings)
        ->toContain('id="internal-access-section"')
        ->toContain('<h3 class="mb-4 text-sm font-semibold text-black dark:text-fg">Internal access</h3>')
        ->not->toContain('<x-application.settings-section')
        ->toContain('Internal hostname')
        ->toContain('Docker network')
        ->toContain('Exposed ports')
        ->toContain('Network aliases')
        ->toContain('wire:init="loadCurrentInternalHostname"')
        ->toContain('$currentInternalHostname')
        ->toContain('class="input input-with-copy-button bg-white dark:bg-coolgray-100 dark:read-only:bg-coolgray-100 dark:read-only:text-white"')
        ->not->toContain('Changes with each deployment')
        ->toContain("window.scrollToSettingsSection?.('networking-section')")
        ->and($generalComponent)
        ->not->toContain('public function loadCurrentInternalHostname(): void')
        ->and($internalAccessComponent)
        ->toContain('public ?string $currentInternalHostname = null;')
        ->toContain('public function loadCurrentInternalHostname(): void')
        ->toContain('getCurrentApplicationContainerStatus(')
        ->toContain("data_get(\$currentContainer, 'Names')")
        ->and($configurationSidebar)
        ->toContain("['id' => 'access-section', 'label' => 'Access']");
});

it('does not show the internal access section for Docker Compose applications', function () {
    $generalSettings = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $configurationSidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($generalSettings)
        ->toContain("@if (\$buildPack !== 'dockercompose')")
        ->and($configurationSidebar)
        ->toContain("['id' => 'access-section', 'label' => 'Access']")
        ->not->toContain("['id' => 'public-access-section', 'label' => 'Public access']")
        ->not->toContain("['id' => 'internal-access-section', 'label' => 'Internal access']");
});
