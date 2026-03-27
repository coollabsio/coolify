<?php

use App\Livewire\Project\New\Select;
use Illuminate\Support\Collection;

it('keeps laravel rootkit and removes laravel with mariadb variants', function () {
    $component = new Select;
    $normalizeMethod = new ReflectionMethod($component, 'normalizeDuplicateLaravelTemplates');
    $normalizeMethod->setAccessible(true);
    $ensureMethod = new ReflectionMethod($component, 'ensureProtectedTemplatesExist');
    $ensureMethod->setAccessible(true);

    $input = collect([
        'laravel-with-mariadb' => [
            'name' => 'laravel-with-mariadb',
        ],
        'legacy-key-1' => [
            'name' => 'Laravel With Mariadb',
        ],
        'legacy-key-2' => [
            'name' => 'Laravel Github Mariadb Phpmyadmin',
        ],
    ]);

    /** @var Collection $normalized */
    $normalized = $normalizeMethod->invoke($component, $input);

    /** @var Collection $result */
    $result = $ensureMethod->invoke($component, $normalized);

    expect($result->has('laravel-rootkit'))->toBeTrue()
        ->and($result->has('laravel-with-mariadb'))->toBeFalse()
        ->and($result->contains(fn ($service) => str(data_get($service, 'name', ''))->lower()->contains('laravel with mariadb')))->toBeFalse();
});

