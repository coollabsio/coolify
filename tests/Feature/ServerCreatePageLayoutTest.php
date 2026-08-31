<?php

test('server creation uses the standard page title without redundant navigation', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->toContain('<h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">New server</h1>')
        ->toContain('class="mb-5 flex min-h-9 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"')
        ->not->toContain('Back to servers')
        ->not->toContain('title="Add a server"');
});

test('provider pages only show the token action in the account panel', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->not->toContain('New token')
        ->not->toContain('new-server-token-')
        ->not->toContain('tokenProviderName');
});

test('server selection separates existing servers from cloud provisioning', function (string $viewPath) {
    $view = file_get_contents(resource_path($viewPath));

    expect($view)
        ->toContain('Add a server')
        ->toContain('IP address or domain')
        ->toContain('Provision a server')
        ->and(strpos($view, 'Add a server'))->toBeLessThan(strpos($view, 'Provision a server'));
})->with([
    'new server page' => 'views/livewire/server/create.blade.php',
    'onboarding' => 'views/livewire/boarding/index.blade.php',
]);

test('new server sections have vertical spacing', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)->toContain('<div class="application-settings-form flex flex-col gap-6">');
});

test('server selection uses the provider logos', function () {
    $newServerView = file_get_contents(resource_path('views/livewire/server/create.blade.php'));
    $onboardingView = file_get_contents(resource_path('views/livewire/boarding/index.blade.php'));

    expect($newServerView)
        ->toContain('src="https://www.vultr.com/media/logo_ondark.svg"')
        ->toContain("src=\"{{ asset('svgs/hetzner.svg') }}\"")
        ->and($onboardingView)
        ->toContain('src="https://www.vultr.com/media/logo_ondark.svg"')
        ->toContain("src=\"{{ asset('svgs/hetzner.svg') }}\"");
});

test('new server cards do not show method badges', function () {
    $view = file_get_contents(resource_path('views/livewire/server/create.blade.php'));

    expect($view)
        ->not->toContain("\n                                    Manual\n")
        ->not->toContain("\n                                        Provider\n");
});
