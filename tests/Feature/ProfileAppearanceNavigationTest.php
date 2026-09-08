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

it('offers full and centered page width preferences on the profile appearance view', function () {
    $appearanceView = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($appearanceView)
        ->not->toContain('<x-profile.navbar />')
        ->toContain('Color theme')
        ->toContain('Page width')
        ->toContain("pageWidth: localStorage.getItem('pageWidth') || 'full'")
        ->toContain("['value' => 'full'")
        ->toContain("['value' => 'centered'")
        ->toContain("@click=\"setWidth('{{ \$option['value'] }}')\"")
        ->toContain("localStorage.setItem('pageWidth', width)")
        ->not->toContain('Interface density')
        ->not->toContain('setZoom(')
        ->and($appLayout)
        ->toContain("pageWidth: localStorage.getItem('pageWidth') || 'full'")
        ->toContain('@page-width-changed.window="pageWidth = $event.detail"')
        ->toContain("pageWidth === 'centered' ? 'mx-auto max-w-[1400px]' : 'max-w-none'");
});
