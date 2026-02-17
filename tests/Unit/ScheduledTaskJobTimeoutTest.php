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

    // Verify it's public (so it survives job serialization for timeout handling)
    $property = $reflection->getProperty('executionId');
    expect($property->isPublic())->toBeTrue();
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

it('failed method creates execution record as last resort', function () {
    // Read the failed() method source code to verify fallback execution creation
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('failed');

    // Get the file and method source
    $filename = $reflection->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $source = file($filename);
    $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation creates an execution record when none is found
    expect($methodSource)
        ->toContain('ScheduledTaskExecution::create')
        ->toContain('last resort');
});

it('failed method preserves existing output in execution message', function () {
    // Read the failed() method source code to verify it preserves existing output
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('failed');

    // Get the file and method source
    $filename = $reflection->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();

    $source = file($filename);
    $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation preserves existing message content
    expect($methodSource)
        ->toContain('$execution->message');
});

it('has parseExitCode method for extracting exit codes', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    expect($reflection->hasMethod('parseExitCode'))->toBeTrue();

    $method = $reflection->getMethod('parseExitCode');
    expect($method->isPrivate())->toBeTrue();
});

it('has stripExitCodeLine method for cleaning output', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    expect($reflection->hasMethod('stripExitCodeLine'))->toBeTrue();

    $method = $reflection->getMethod('stripExitCodeLine');
    expect($method->isPrivate())->toBeTrue();
});

it('parseExitCode correctly extracts exit codes from output', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('parseExitCode');
    $method->setAccessible(true);

    // Create an uninitialized instance to call the method on
    $instance = $reflection->newInstanceWithoutConstructor();

    // Test successful exit code
    expect($method->invoke($instance, "some output\nCOOLIFY_EXIT_CODE:0"))->toBe(0);

    // Test failed exit code
    expect($method->invoke($instance, "error output\nCOOLIFY_EXIT_CODE:1"))->toBe(1);

    // Test higher exit code
    expect($method->invoke($instance, "output\nCOOLIFY_EXIT_CODE:127"))->toBe(127);

    // Test null output
    expect($method->invoke($instance, null))->toBe(1);

    // Test empty output
    expect($method->invoke($instance, ''))->toBe(1);

    // Test output without marker
    expect($method->invoke($instance, 'just some output'))->toBe(1);
});

it('stripExitCodeLine correctly removes the marker from output', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('stripExitCodeLine');
    $method->setAccessible(true);

    $instance = $reflection->newInstanceWithoutConstructor();

    // Test normal output with marker
    expect($method->invoke($instance, "hello world\nCOOLIFY_EXIT_CODE:0"))->toBe('hello world');

    // Test multiline output with marker
    expect($method->invoke($instance, "line1\nline2\nCOOLIFY_EXIT_CODE:1"))->toBe("line1\nline2");

    // Test marker-only output
    expect($method->invoke($instance, 'COOLIFY_EXIT_CODE:0'))->toBeNull();

    // Test null output
    expect($method->invoke($instance, null))->toBeNull();

    // Test empty output
    expect($method->invoke($instance, ''))->toBe('');
});
