<?php

use App\Livewire\SettingsDropdown;

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
