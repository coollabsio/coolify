<?php

use App\Livewire\SettingsDropdown;

it('keeps changelog and the theme switcher in the sidebar without the old preferences trigger', function () {
    $navbarView = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbarView)
        ->toContain('<livewire:settings-dropdown trigger="changelog-sidebar" />')
        ->not->toContain('<livewire:settings-dropdown />')
        ->toContain('aria-label="Theme switcher"')
        ->toContain('aria-label="Use light theme"')
        ->toContain('aria-label="Use system theme"')
        ->toContain('aria-label="Use dark theme"')
        ->toContain('cycleTheme()')
        ->toContain("const themes = ['light', 'system', 'dark'];")
        ->toContain('pl-2 pr-3 items-start gap-3')
        ->toContain('class="flex min-w-0 flex-1 flex-col"')
        ->toContain('class="min-w-0 flex-1"')
        ->toContain('class="flex h-8 w-full items-center justify-between');
});

it('keeps changelog and appearance options out of the preferences dropdown', function () {
    $dropdownView = file_get_contents(resource_path('views/livewire/settings-dropdown.blade.php'));

    expect($dropdownView)
        ->toContain("\$trigger === 'changelog-sidebar'")
        ->toContain('title="What\'s New"')
        ->toContain('aria-label="What\'s New"')
        ->toContain('wire:click="openWhatsNewModal"')
        ->toContain('class="relative text-left menu-item"')
        ->toContain('class="text-left menu-item-label"')
        ->toContain("What's New</span>")
        ->toContain('M9.813 15.904 9 18.75')
        ->not->toContain('<span>Changelog</span>')
        ->not->toContain('Appearance</div>')
        ->not->toContain("@click=\"setTheme('dark'); dropdownOpen = false\"")
        ->not->toContain("@click=\"setTheme('light'); dropdownOpen = false\"")
        ->not->toContain("@click=\"setTheme('system'); dropdownOpen = false\"");
});

it('opens and closes the changelog modal state', function () {
    $component = new SettingsDropdown;
    $component->trigger = 'changelog-sidebar';

    expect($component->trigger)->toBe('changelog-sidebar')
        ->and($component->showWhatsNewModal)->toBeFalse();

    $component->openWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeTrue();

    $component->closeWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeFalse();
});

it('uses the default button palette for the changelog fetch action in light mode', function () {
    $dropdownView = file_get_contents(resource_path('views/livewire/settings-dropdown.blade.php'));

    expect($dropdownView)
        ->toContain('wire:click="manualFetchChangelog"')
        ->not->toContain('bg-coolgray-200 hover:bg-coolgray-300');
});
