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

test('page body uses the dynamic viewport height on mobile', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/body\s*\{[^}]*min-height:\s*100dvh;/s')
        ->not->toMatch('/body\s*\{[^}]*@apply[^;]*min-h-screen/s');
});

test('auth pages use the Coollabs purple background glow', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.auth-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-coollabs\) 9%, transparent\)/s')
        ->not->toMatch('/\.auth-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-accent\) 9%, transparent\)/s');
});

test('error pages use the Coollabs purple background glow', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.error-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-coollabs\) 9%, transparent\)/s')
        ->not->toMatch('/\.error-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-accent\) 9%, transparent\)/s');
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

test('email verification page uses the shared auth shell', function () {
    $verification = file_get_contents(resource_path('views/auth/verify-email.blade.php'));

    expect($verification)
        ->toContain('x-layout-simple')
        ->toContain('x-auth.shell')
        ->toContain('title="Coolify"')
        ->toContain('auth-guidance')
        ->toContain('block sm:inline')
        ->not->toContain('<x-layout>')
        ->not->toContain('md:h-screen');
});

test('team invitation page uses the shared auth shell', function () {
    $invitation = file_get_contents(resource_path('views/invitation/accept.blade.php'));

    expect($invitation)
        ->toContain('x-auth.shell')
        ->toContain('title="Coolify"')
        ->toContain('x-auth.alert')
        ->toContain('Accept invitation')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});
