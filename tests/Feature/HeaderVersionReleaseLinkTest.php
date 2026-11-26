<?php

use Illuminate\Support\Facades\Blade;

test('development versions are not linked to nonexistent github releases', function () {
    config(['constants.coolify.version' => '4.3.1-dev.d64cbda3e']);

    $version = Blade::render('<x-version />');

    expect($version)
        ->toContain('v4.3.1-dev.d64cbda3e')
        ->not->toContain('href=')
        ->not->toContain('target="_blank"');
});
