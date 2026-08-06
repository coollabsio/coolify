<?php

it('shows internal Docker access details in application general settings', function () {
    $generalSettings = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $generalComponent = file_get_contents(app_path('Livewire/Project/Application/General.php'));
    $configurationSidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($generalSettings)
        ->toContain('id="internal-access-section"')
        ->toContain('title="Internal access"')
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
        ->toContain('public ?string $currentInternalHostname = null;')
        ->toContain('public function loadCurrentInternalHostname(): void')
        ->toContain('getCurrentApplicationContainerStatus(')
        ->toContain("data_get(\$currentContainer, 'Names')")
        ->and($configurationSidebar)
        ->toContain("['id' => 'internal-access-section', 'label' => 'Internal access']");
});

it('does not show the internal access section for Docker Compose applications', function () {
    $generalSettings = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));
    $configurationSidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($generalSettings)
        ->toContain("@if (\$buildPack !== 'dockercompose')")
        ->and($configurationSidebar)
        ->toContain("\$isComposeApp ? null : ['id' => 'internal-access-section', 'label' => 'Internal access']");
});
