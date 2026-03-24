<?php

use App\Livewire\Project\New\Select;
use Illuminate\Support\Collection;

it('ensures laravel template exists in protected templates recovery', function () {
    $component = new Select;
    $method = new ReflectionMethod($component, 'ensureProtectedTemplatesExist');
    $method->setAccessible(true);

    /** @var Collection $result */
    $result = $method->invoke($component, collect());

    expect($result->has('laravel-with-mariadb'))->toBeTrue();
});

