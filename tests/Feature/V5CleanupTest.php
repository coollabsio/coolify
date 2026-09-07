<?php

use Illuminate\Support\Facades\Route;

it('does not register the archived v5 interface', function () {
    expect(Route::has('v5.dashboard'))->toBeFalse()
        ->and(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'v5')))->toBeFalse();
});

it('keeps v5 prototypes outside executable application paths', function () {
    expect(is_dir(app_path('Support/V5')))->toBeFalse()
        ->and(is_dir(resource_path('js/v5')))->toBeFalse()
        ->and(is_dir(resource_path('css/v5')))->toBeFalse()
        ->and(is_dir(resource_path('views/v5')))->toBeFalse()
        ->and(file_exists(base_path('routes/v5.php')))->toBeFalse()
        ->and(file_exists(config_path('v5.php')))->toBeFalse();
});

it('archives the previous v5 migrations and ui as documentation', function () {
    expect(glob(base_path('docs/v5/migrations/*.php.txt')))->toHaveCount(15)
        ->and(glob(base_path('docs/v5/ui/resources/js/v5/**/*.txt')))->not->toBeEmpty()
        ->and(file_exists(base_path('docs/v5/ui/resources/views/v5/app.blade.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/app/Support/V5/V5Feature.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/routes/v5.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/tests/v5/Browser/DashboardSmokeTest.php.txt')))->toBeTrue();
});

it('documents removed v5 packages and the v4 animation dependency', function () {
    $packageDocumentation = file_get_contents(base_path('docs/v5/package.md'));

    expect($packageDocumentation)
        ->toContain('`inertiajs/inertia-laravel`')
        ->toContain('`react`')
        ->toContain('`@vitejs/plugin-react`')
        ->toContain('`tw-animate-css`')
        ->toContain('must not be removed');
});
