<?php

use App\Jobs\ScheduledTaskJob;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Mock Log facade to prevent actual logging during tests
    Log::spy();
});

it('has executionId property for timeout handling', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    // Verify executionId property exists
    expect($reflection->hasProperty('executionId'))->toBeTrue();

    // Verify it's protected (will be serialized with the job)
    $property = $reflection->getProperty('executionId');
    expect($property->isProtected())->toBeTrue();
});

it('has failed method that handles job failures', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    // Verify failed() method exists
    expect($reflection->hasMethod('failed'))->toBeTrue();

    // Verify it accepts a Throwable parameter
    $method = $reflection->getMethod('failed');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getName())->toBe('exception');
    expect($parameters[0]->allowsNull())->toBeTrue();
});

it('failed method implementation reloads execution from database', function () {
    // Read the failed() method source code to verify it reloads from database
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('failed');

    // Get the file and method source
    $filename = $reflection->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $source = file($filename);
    $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation includes reloading from database
    expect($methodSource)
        ->toContain('$this->executionId')
        ->toContain('ScheduledTaskExecution::find')
        ->toContain('ScheduledTaskExecution::query')
        ->toContain('scheduled_task_id')
        ->toContain('orderBy')
        ->toContain('status')
        ->toContain('failed')
        ->toContain('notify');
});

it('failed method updates execution with error_details field', function () {
    // Read the failed() method source code to verify error_details is populated
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('failed');

    // Get the file and method source
    $filename = $reflection->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $source = file($filename);
    $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation populates error_details field
    expect($methodSource)->toContain('error_details');
});

it('failed method logs when execution cannot be found', function () {
    // Read the failed() method source code to verify defensive logging
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('failed');

    // Get the file and method source
    $filename = $reflection->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $source = file($filename);
    $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation logs a warning if execution is not found
    expect($methodSource)
        ->toContain('Could not find execution log')
        ->toContain('warning');
});
