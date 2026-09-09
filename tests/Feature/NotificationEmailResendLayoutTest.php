<?php

test('team notification resend section matches instance email layout', function () {
    $instance = file_get_contents(resource_path('views/livewire/settings-email.blade.php'));
    $team = file_get_contents(resource_path('views/livewire/notifications/email.blade.php'));

    preg_match('/settings-section title="Resend".*?<\/x-application.settings-section>/s', $instance, $instanceResend);
    preg_match('/settings-section title="Resend".*?<\/x-application.settings-section>/s', $team, $teamResend);

    expect($instanceResend[0] ?? '')
        ->not->toBeEmpty()
        ->toContain('grid gap-4 lg:grid-cols-2')
        ->toContain('id="resendEnabled"')
        ->toContain('id="resendApiKey"')
        ->not->toContain('lg:col-span-2')
        ->not->toContain('description=');

    expect($teamResend[0] ?? '')
        ->not->toBeEmpty()
        ->toContain('grid gap-4 lg:grid-cols-2')
        ->toContain('id="resendEnabled"')
        ->toContain('id="resendApiKey"')
        ->not->toContain('lg:col-span-2')
        ->not->toContain('description=');
});
