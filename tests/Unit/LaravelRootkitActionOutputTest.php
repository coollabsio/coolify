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
        ->toContain('Failed at: php artisan migrate --force')
        ->toContain('runLaravelMaintenanceCommand')
        ->toContain("'clear-config-and-cache'")
        ->toContain("'config-cache'")
        ->toContain("'queue-restart'")
        ->toContain("'queue-work-once'");
});

it('configures laravel rootkit to use file cache and guarded schedule run mode', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        ->toContain('php artisan schedule:run --no-interaction')
        ->toContain('SERVICE_LARAVEL_SCHEDULER_ENABLED')
        ->toContain('SERVICE_LARAVEL_QUEUE_NAMES')
        ->toContain('php artisan schedule:list --no-interaction')
        ->toContain('queue:work --queue=${SERVICE_LARAVEL_QUEUE_NAMES:-default}')
        ->toContain('numprocs=6')
        ->toContain('php -m | grep -q "^exif$"')
        ->toContain('pdo_mysql zip bcmath gd intl exif mbstring opcache')
        ->toContain('condition: service_started')
        ->toContain('No such container')
        ->toContain('command: ["nginx", "-g", "daemon off;"]')
        ->toContain('try_files $uri $uri/ /index.php?$query_string;')
        ->toContain('CACHE_STORE=file')
        ->toContain('upsert_env "CACHE_STORE" "file"')
        ->toContain('upsert_env "SESSION_DRIVER" "file"')
        ->toContain('if [ -z "${CURRENT_APP_KEY}" ]; then')
        ->not->toContain('upsert_env "APP_KEY" ""');
});

it('loads cron tasks from artisan schedule list or project source fallback', function () {
    $cronFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/LaravelCron.php');
    $cronView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/laravel-cron.blade.php');

    expect($cronFile)
        ->toContain('php artisan schedule:list --format=json --no-interaction')
        ->toContain('php artisan schedule:list --json --no-interaction')
        ->toContain('parseScheduleSourceOutput')
        ->toContain('Showing schedule definitions detected in project source')
        ->toContain('sanitizeScheduleCommandOutput');

    expect($cronView)
        ->toContain('Origen')
        ->toContain('text-black');
});
