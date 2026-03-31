<?php

it('reports deployed commits and focused failure stages in rootkit stack actions', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        ->toContain('New commits deployed:')
        ->toContain('No new commits to deploy.')
        ->toContain('Failed at: composer install')
        ->toContain('Failed at: npm install/build')
        ->toContain('Migration status before run:')
        ->toContain('Migrations completed successfully with no warnings.')
        ->toContain('Failed at: php artisan migrate --force');
});

it('configures laravel rootkit to use file cache and schedule work mode', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        ->toContain('php artisan schedule:work --no-interaction')
        ->toContain('CACHE_STORE=file')
        ->toContain('upsert_env "CACHE_STORE" "file"')
        ->toContain('upsert_env "SESSION_DRIVER" "file"');
});

it('loads cron tasks from artisan schedule list or project source fallback', function () {
    $cronFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/LaravelCron.php');
    $cronView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/laravel-cron.blade.php');

    expect($cronFile)
        ->toContain('php artisan schedule:list --format=json --no-interaction')
        ->toContain('php artisan schedule:list --json --no-interaction')
        ->toContain('parseScheduleSourceOutput')
        ->toContain('Showing schedule definitions detected in project source');

    expect($cronView)->toContain('Origen');
});
