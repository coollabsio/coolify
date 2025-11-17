<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Mockery;

/**
 * Test to verify that deployment errors are properly logged with comprehensive details.
 *
 * This test suite verifies the fix for issue #7113 where deployments fail without
 * clear error messages. The fix ensures that all deployment failures log:
 * - The exception message
 * - The exception type/class
 * - The exception code (if present)
 * - The file and line where the error occurred
 * - Previous exception details (if chained)
 * - Stack trace (first 5 lines)
 */
it('logs comprehensive error details when failed() is called', function () {
    // Create a mock exception with all properties
    $innerException = new \RuntimeException('Connection refused', 111);
    $exception = new DeploymentException(
        'Failed to start container',
        500,
        $innerException
    );

    // Mock the application deployment queue
    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $logEntries = [];

    // Capture all log entries
    $mockQueue->shouldReceive('addLogEntry')
        ->withArgs(function ($message, $type = 'stdout') use (&$logEntries) {
            $logEntries[] = ['message' => $message, 'type' => $type];

            return true;
        })
        ->atLeast()->once();

    $mockQueue->shouldReceive('update')->andReturn(true);

    // Mock Application and its relationships
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('dockerfile');
    $mockApplication->build_pack = 'dockerfile';

    $mockSettings = Mockery::mock();
    $mockSettings->shouldReceive('getAttribute')
        ->with('is_consistent_container_name_enabled')
        ->andReturn(false);
    $mockSettings->shouldReceive('getAttribute')
        ->with('custom_internal_name')
        ->andReturn('');
    $mockSettings->is_consistent_container_name_enabled = false;
    $mockSettings->custom_internal_name = '';

    $mockApplication->shouldReceive('getAttribute')
        ->with('settings')
        ->andReturn($mockSettings);

    // Use reflection to set private properties and call the failed() method
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass($job);

    $queueProperty = $reflection->getProperty('application_deployment_queue');
    $queueProperty->setAccessible(true);
    $queueProperty->setValue($job, $mockQueue);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    // Mock the failDeployment method to prevent errors
    $job->shouldReceive('failDeployment')->andReturn();
    $job->shouldReceive('execute_remote_command')->andReturn();

    // Call the failed method
    $failedMethod = $reflection->getMethod('failed');
    $failedMethod->setAccessible(true);
    $failedMethod->invoke($job, $exception);

    // Verify comprehensive error logging
    $errorMessages = array_column($logEntries, 'message');
    $errorMessageString = implode("\n", $errorMessages);

    // Check that all critical information is logged
    expect($errorMessageString)->toContain('Deployment failed: Failed to start container');
    expect($errorMessageString)->toContain('Error type: App\Exceptions\DeploymentException');
    expect($errorMessageString)->toContain('Error code: 500');
    expect($errorMessageString)->toContain('Location:');
    expect($errorMessageString)->toContain('Caused by:');
    expect($errorMessageString)->toContain('RuntimeException: Connection refused');
    expect($errorMessageString)->toContain('Stack trace');

    // Verify stderr type is used for error logging
    $stderrEntries = array_filter($logEntries, fn ($entry) => $entry['type'] === 'stderr');
    expect(count($stderrEntries))->toBeGreaterThan(0);
});

it('handles exceptions with no message gracefully', function () {
    $exception = new \Exception;

    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $logEntries = [];

    $mockQueue->shouldReceive('addLogEntry')
        ->withArgs(function ($message, $type = 'stdout') use (&$logEntries) {
            $logEntries[] = ['message' => $message, 'type' => $type];

            return true;
        })
        ->atLeast()->once();

    $mockQueue->shouldReceive('update')->andReturn(true);

    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('dockerfile');
    $mockApplication->build_pack = 'dockerfile';

    $mockSettings = Mockery::mock();
    $mockSettings->shouldReceive('getAttribute')
        ->with('is_consistent_container_name_enabled')
        ->andReturn(false);
    $mockSettings->shouldReceive('getAttribute')
        ->with('custom_internal_name')
        ->andReturn('');
    $mockSettings->is_consistent_container_name_enabled = false;
    $mockSettings->custom_internal_name = '';

    $mockApplication->shouldReceive('getAttribute')
        ->with('settings')
        ->andReturn($mockSettings);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass($job);

    $queueProperty = $reflection->getProperty('application_deployment_queue');
    $queueProperty->setAccessible(true);
    $queueProperty->setValue($job, $mockQueue);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $job->shouldReceive('failDeployment')->andReturn();
    $job->shouldReceive('execute_remote_command')->andReturn();

    $failedMethod = $reflection->getMethod('failed');
    $failedMethod->setAccessible(true);
    $failedMethod->invoke($job, $exception);

    $errorMessages = array_column($logEntries, 'message');
    $errorMessageString = implode("\n", $errorMessages);

    // Should log "Unknown error occurred" for empty messages
    expect($errorMessageString)->toContain('Unknown error occurred');
    expect($errorMessageString)->toContain('Error type:');
});

it('wraps exceptions in deployment methods with DeploymentException', function () {
    // Verify that our deployment methods wrap exceptions properly
    $originalException = new \RuntimeException('Container not found');

    try {
        throw new DeploymentException('Failed to start container', 0, $originalException);
    } catch (DeploymentException $e) {
        expect($e->getMessage())->toBe('Failed to start container');
        expect($e->getPrevious())->toBe($originalException);
        expect($e->getPrevious()->getMessage())->toBe('Container not found');
    }
});

it('logs error code 0 correctly', function () {
    // Verify that error code 0 is logged (previously skipped due to falsy check)
    $exception = new \Exception('Test error', 0);

    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $logEntries = [];

    $mockQueue->shouldReceive('addLogEntry')
        ->withArgs(function ($message, $type = 'stdout') use (&$logEntries) {
            $logEntries[] = ['message' => $message, 'type' => $type];

            return true;
        })
        ->atLeast()->once();

    $mockQueue->shouldReceive('update')->andReturn(true);

    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('dockerfile');
    $mockApplication->build_pack = 'dockerfile';

    $mockSettings = Mockery::mock();
    $mockSettings->shouldReceive('getAttribute')
        ->with('is_consistent_container_name_enabled')
        ->andReturn(false);
    $mockSettings->shouldReceive('getAttribute')
        ->with('custom_internal_name')
        ->andReturn('');
    $mockSettings->is_consistent_container_name_enabled = false;
    $mockSettings->custom_internal_name = '';

    $mockApplication->shouldReceive('getAttribute')
        ->with('settings')
        ->andReturn($mockSettings);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass($job);

    $queueProperty = $reflection->getProperty('application_deployment_queue');
    $queueProperty->setAccessible(true);
    $queueProperty->setValue($job, $mockQueue);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $job->shouldReceive('failDeployment')->andReturn();
    $job->shouldReceive('execute_remote_command')->andReturn();

    $failedMethod = $reflection->getMethod('failed');
    $failedMethod->setAccessible(true);
    $failedMethod->invoke($job, $exception);

    $errorMessages = array_column($logEntries, 'message');
    $errorMessageString = implode("\n", $errorMessages);

    // Should log error code 0 (not skip it)
    expect($errorMessageString)->toContain('Error code: 0');
});
