<?php

it('keeps backup and transactional email out of the settings top navigation', function () {
    $this->blade('<x-settings.navbar />')
        ->assertSeeText('Configuration')
        ->assertSeeText('OAuth')
        ->assertSeeText('Scheduled Jobs')
        ->assertDontSeeText('Instance Backup')
        ->assertDontSeeText('Transactional Email');
});

it('shows backup and transactional email in the settings configuration sidebar', function () {
    $view = $this->blade('<x-settings.sidebar activeMenu="backup" />')
        ->assertSeeTextInOrder([
            'General',
            'Advanced',
            'Instance Backup',
            'Transactional Email',
            'Updates',
        ]);

    expect((string) $view)
        ->toContain(route('settings.backup'))
        ->toContain(route('settings.email'))
        ->and(substr_count((string) $view, 'menu-item-active'))->toBe(1);
});

it('renders backup and transactional email pages with the settings configuration sidebar', function () {
    expect(file_get_contents(resource_path('views/livewire/settings-backup.blade.php')))
        ->toContain('<x-settings.sidebar activeMenu="backup" />')
        ->and(file_get_contents(resource_path('views/livewire/settings-email.blade.php')))
        ->toContain('<x-settings.sidebar activeMenu="email" />');
});

it('uses the same title and description spacing on backup and transactional email settings pages', function () {
    expect(file_get_contents(resource_path('views/livewire/settings-backup.blade.php')))
        ->not->toContain('class="flex items-center gap-2 pb-2"')
        ->toContain('<div class="pb-4">Instance backup configuration for Coolify instance.</div>')
        ->and(file_get_contents(resource_path('views/livewire/settings-email.blade.php')))
        ->not->toContain('class="flex flex-col gap-2 pb-4"')
        ->toContain('<div class="pb-4">Instance wide email settings for password resets, invitations, etc.</div>');
});

it('uses instance backup as the backup settings label', function () {
    expect(file_get_contents(resource_path('views/components/settings/sidebar.blade.php')))
        ->toContain('<span class="menu-item-label">Instance Backup</span>')
        ->not->toContain('<span class="menu-item-label">Backup</span>')
        ->and(file_get_contents(resource_path('views/livewire/settings-backup.blade.php')))
        ->toContain('<h2>Instance Backup</h2>')
        ->toContain('Instance backup configuration for Coolify instance.')
        ->not->toContain('<h2>Backup</h2>');
});
