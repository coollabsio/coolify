<?php

use Illuminate\Support\Facades\File;

it('keeps laravel-rootkit in the on-disk service catalog', function () {
    $path = base_path('templates/'.config('constants.services.file_name'));

    expect(File::exists($path))->toBeTrue();

    $services = json_decode((string) File::get($path), true);

    expect($services)->toBeArray();
    expect($services)->toHaveKey('laravel-rootkit');
    expect($services['laravel-rootkit'])
        ->toHaveKey('compose')
        ->toHaveKey('tags')
        ->toHaveKey('category');
});

it('ensure_protected_service_templates re-injects laravel-rootkit when stripped', function () {
    $services = collect([
        'some-other-service' => ['name' => 'some-other-service'],
    ]);

    $result = ensure_protected_service_templates($services);

    expect($result->has('laravel-rootkit'))->toBeTrue();

    $rootkit = $result->get('laravel-rootkit');
    expect($rootkit)
        ->toHaveKey('compose')
        ->toHaveKey('slogan')
        ->toHaveKey('tags')
        ->toHaveKey('category');

    // The compose blob must round-trip through base64 back to the YAML on disk.
    $decoded = base64_decode($rootkit['compose'], true);
    expect($decoded)
        ->toBeString()
        ->toContain('image: php:${SERVICE_PHP_VERSION:-8.4}-fpm-alpine');
});

it('persist_service_templates_catalog writes the catalog with laravel-rootkit included', function () {
    $path = base_path('templates/'.config('constants.services.file_name'));
    $backup = File::get($path);

    try {
        // Simulate a hostile upstream response that does not know about
        // our local templates.
        persist_service_templates_catalog(collect([
            'nginx' => ['name' => 'nginx'],
        ]));

        $written = json_decode((string) File::get($path), true);
        expect($written)->toHaveKey('laravel-rootkit');
        expect($written)->toHaveKey('nginx');
    } finally {
        File::put($path, $backup);
    }
});

it('PullTemplatesFromCDN job source calls the protected-templates helper', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/PullTemplatesFromCDN.php');

    expect($source)
        ->toContain('persist_service_templates_catalog(')
        ->not->toContain("File::put(base_path('templates/'.config('constants.services.file_name')), json_encode(\$services))");
});

it('Init command source calls the protected-templates helper', function () {
    $source = file_get_contents(__DIR__.'/../../app/Console/Commands/Init.php');

    expect($source)
        ->toContain('persist_service_templates_catalog(')
        ->not->toContain("File::put(base_path('templates/'.config('constants.services.file_name')), json_encode(\$services))");
});
