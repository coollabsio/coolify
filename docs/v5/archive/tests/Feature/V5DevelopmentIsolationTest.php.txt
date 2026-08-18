<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

function inspectV5RegistrationForEnvironment(string $environment): array
{
    $script = <<<'PHP'
    $environment = $argv[1];
    putenv("APP_ENV={$environment}");
    $_ENV['APP_ENV'] = $environment;
    $_SERVER['APP_ENV'] = $environment;

    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo json_encode([
        'routes' => collect($app['router']->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all(),
        'migration_paths' => $app->make('migrator')->paths(),
        'horizon_supervisors' => array_keys(config("horizon.environments.{$environment}", [])),
    ], JSON_THROW_ON_ERROR);
    PHP;

    $process = new Process([PHP_BINARY, '-r', $script, $environment], base_path());
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('registers v5 routes, migrations, and workers in development environments', function (string $environment) {
    $registration = inspectV5RegistrationForEnvironment($environment);

    expect($registration['routes'])
        ->toContain('v5')
        ->toContain('api/v1/internal/flux/resource-status')
        ->and($registration['migration_paths'])->toContain(database_path('migrations-v5'))
        ->and($registration['horizon_supervisors'])->toContain('v5reconcile');
})->with(['local', 'development', 'dev', 'testing']);

it('does not register v5 routes, migrations, or workers outside development', function (string $environment) {
    $registration = inspectV5RegistrationForEnvironment($environment);

    expect($registration['routes'])
        ->not->toContain('v5')
        ->not->toContain('api/v1/internal/flux/resource-status')
        ->and($registration['migration_paths'])->not->toContain(database_path('migrations-v5'))
        ->and($registration['horizon_supervisors'])->not->toContain('v5reconcile');
})->with(['production', 'staging']);

it('keeps v5 schema changes out of the default migration path', function () {
    $v5MigrationFiles = collect(glob(database_path('migrations/*.php')))
        ->filter(fn (string $path) => str_contains((string) file_get_contents($path), "'v5_"));

    expect($v5MigrationFiles)->toBeEmpty();
});

it('does not ship the v5 Flux runtime in the production container', function () {
    $productionCompose = file_get_contents(base_path('docker-compose.prod.yml'));
    $productionDockerfile = file_get_contents(base_path('docker/production/Dockerfile'));

    expect($productionCompose)
        ->not->toContain('COOLIFY_FLUX')
        ->not->toContain('/data/coolify/flux')
        ->and($productionDockerfile)
        ->not->toContain('COOLIFY_FLUX_VERSION')
        ->not->toContain('/usr/local/bin/flux');
});

it('blocks v5 console commands when v5 is disabled', function (string $command) {
    config()->set('v5.enabled', false);

    expect(Artisan::call($command))->toBe(1)
        ->and(Artisan::output())->toContain('V5 is only available in development environments.');
})->with([
    'flux:dev',
    'v5:flux-generate-keys',
    'v5:sync-dev-lima-servers',
]);
