<?php

it('uses the account menu as the only profile navigation', function () {
    $routes = file_get_contents(base_path('routes/web.php'));
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($routes)
        ->toContain("Route::get('/profile/appearance', ProfileAppearance::class)->name('profile.appearance')")
        ->and($profileView)
        ->not->toContain('<x-profile.navbar />')
        ->not->toContain('<h1>Profile</h1>\n    <div class="subtitle -mt-2">');
});

it('opens the email change form without a Livewire request', function () {
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($profileView)
        ->toContain('@click="openEmailModal()"')
        ->toContain('this.$refs.newEmailInput?.focus()')
        ->toContain('x-ref="newEmailInput"')
        ->toContain('x-show="emailModalOpen"')
        ->toContain('x-teleport="body"')
        ->toContain('Change email')
        ->toContain('Send code')
        ->toContain('Verify email')
        ->not->toContain('>Cancel</x-forms.button>')
        ->not->toContain('wire:click="showEmailChangeForm"');
});

it('does not show a redundant enabled badge for two-factor authentication', function () {
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($profileView)
        ->not->toContain('<x-status-badge status="Enabled" type="success" />');
});

it('keeps color theme preferences on the profile appearance view without page width or density controls', function () {
    $appearanceView = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));

    expect($appearanceView)
        ->not->toContain('<x-profile.navbar />')
        ->toContain('Color theme')
        ->toContain("setTheme('{{ \$option['value'] }}')")
        ->toContain("['value' => 'light'")
        ->toContain("['value' => 'system'")
        ->toContain('to-[#050505]')
        ->toContain("['value' => 'dark'")
        ->not->toContain('Page width')
        ->not->toContain('Interface density')
        ->not->toContain('setWidth(')
        ->not->toContain('setZoom(')
        ->not->toContain('pageWidth')
        ->not->toContain("localStorage.getItem('zoom')")
        ->not->toContain("localStorage.setItem('pageWidth'")
        ->not->toContain("localStorage.setItem('zoom'");
});
