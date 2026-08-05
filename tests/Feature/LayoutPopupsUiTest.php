<?php

/**
 * Layout popups (realtime warning, sponsorship, notifications) use the redesigned UI shell.
 */
test('realtime connection warning uses the redesigned popup shell', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));

    expect($view)
        ->toContain('Cannot connect to real-time service')
        ->toContain('Acknowledge &amp; disable')
        ->toContain('customActions')
        ->toContain('rounded-2xl border border-red-200')
        ->toContain('name="alert-triangle"')
        ->not->toContain('WARNING: </span> Cannot connect to real-time service')
        ->not->toContain('Acknowledge & Disable This Popup');
});

test('notification reminder uses the redesigned popup shell', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));

    expect($view)
        ->toContain('No notifications enabled')
        ->toContain('Accept and close')
        ->toContain('Open notifications')
        ->not->toContain('Accept and Close');
});

test('non-critical reminders collapse after ten seconds', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));

    expect($view)
        ->toContain('reminderCollapseAfter: 10000')
        ->toContain("scheduleReminderCollapse('sponsorship')")
        ->toContain("scheduleReminderCollapse('notification')")
        ->toContain('reminders.sponsorship.compact = true')
        ->toContain('reminders.notification.compact = true');
});
