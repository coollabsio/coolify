<?php

use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;

it('CoolifyTask has correct retry properties defined', function () {
    $reflection = new ReflectionClass(CoolifyTask::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(3)
        ->and($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(600);
});

it('ScheduledTaskJob has correct retry properties defined', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue()
        ->and($reflection->hasMethod('failed'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(3)
        ->and($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(300);
});

it('DatabaseBackupJob has correct retry properties defined', function () {
    $reflection = new ReflectionClass(DatabaseBackupJob::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue()
        ->and($reflection->hasMethod('failed'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(2)
        ->and($defaultProperties['maxExceptions'])->toBe(1);
});
