<?php

use App\Support\ContainerName;

it('normalizes container names', function () {
    expect(ContainerName::normalize(''))->toBe('');
    expect(ContainerName::normalize('laravel-abc'))->toBe('laravel-abc');
    expect(ContainerName::normalize('/laravel-abc'))->toBe('laravel-abc');
    expect(ContainerName::normalize('  /nginx-xyz  '))->toBe('nginx-xyz');
});

