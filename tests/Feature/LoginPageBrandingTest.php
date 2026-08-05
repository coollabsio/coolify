<?php

/**
 * Auth shell headers should brand the product so users know they are on Coolify.
 */
test('login register and forgot password headers are Coolify', function () {
    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));
    $forgot = file_get_contents(resource_path('views/auth/forgot-password.blade.php'));

    expect($login)
        ->toContain('title="Coolify"')
        ->not->toContain('title="Welcome back"');

    expect($register)
        ->toContain('title="Coolify"')
        ->not->toContain(":title=\"\$isFirstUser ? 'Create the root account' : 'Create your account'\"");

    expect($forgot)
        ->toContain('title="Coolify"')
        ->not->toContain("title=\"{{ __('auth.forgot_password_heading') }}\"");
});

test('confirm password page uses the shared auth shell', function () {
    $confirm = file_get_contents(resource_path('views/auth/confirm-password.blade.php'));

    expect($confirm)
        ->toContain('x-auth.shell')
        ->toContain('title="Coolify"')
        ->toContain('x-auth.alert')
        ->toContain('auth-guidance')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('two factor challenge page uses the shared auth shell', function () {
    $challenge = file_get_contents(resource_path('views/auth/two-factor-challenge.blade.php'));

    expect($challenge)
        ->toContain('x-auth.shell')
        ->toContain('title="Coolify"')
        ->toContain('x-auth.alert')
        ->toContain('auth-guidance')
        ->toContain('Verify and continue')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});
