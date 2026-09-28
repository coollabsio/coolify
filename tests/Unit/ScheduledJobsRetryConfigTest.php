<?php

use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Livewire\Project\Database\BackupEdit;
use Livewire\Attributes\Validate;

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

    // Backups run a single attempt (retries were removed on purpose in 49a3bb0da):
    // no $tries override and no backoff schedule, but a failed() handler is kept.
    expect($reflection->hasProperty('tries'))->toBeFalse()
        ->and($reflection->hasMethod('backoff'))->toBeFalse()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('failed'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(3600);
});

it('DatabaseBackupJob enforces minimum timeout of 60 seconds', function () {
    // The minimum is enforced where the backup timeout is set: the UI and the API
    $timeoutProperty = new ReflectionProperty(BackupEdit::class, 'timeout');
    $validateAttributes = $timeoutProperty->getAttributes(Validate::class);

    expect($validateAttributes)->toHaveCount(1)
        ->and($validateAttributes[0]->getArguments()[0])->toContain('min:60');

    $apiController = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/DatabasesController.php');
    expect(substr_count($apiController, "'timeout' => 'integer|min:60|max:36000'"))->toBeGreaterThanOrEqual(2);

    // The job falls back to the default timeout when none is stored
    $reflection = new ReflectionClass(DatabaseBackupJob::class);
    $constructor = $reflection->getMethod('__construct');
    $source = file($reflection->getFileName());
    $constructorSource = implode('', array_slice($source, $constructor->getStartLine() - 1, $constructor->getEndLine() - $constructor->getStartLine() + 1));

    expect($constructorSource)->toContain('$this->timeout = $backup->timeout ?? 3600;');
});
