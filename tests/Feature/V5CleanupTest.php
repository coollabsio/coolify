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
