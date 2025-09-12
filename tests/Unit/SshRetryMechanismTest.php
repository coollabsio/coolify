<?php

namespace Tests\Unit;

use App\Helpers\SshRetryHandler;
use App\Traits\SshRetryable;
use Tests\TestCase;

class SshRetryMechanismTest extends TestCase
{
    public function test_ssh_retry_handler_exists()
    {
        $this->assertTrue(class_exists(\App\Helpers\SshRetryHandler::class));
    }

    public function test_ssh_retryable_trait_exists()
    {
        $this->assertTrue(trait_exists(\App\Traits\SshRetryable::class));
    }

    public function test_retry_on_ssh_connection_errors()
    {
        $handler = new class
        {
            use SshRetryable;

            // Make methods public for testing
            public function test_is_retryable_ssh_error($error)
            {
                return $this->isRetryableSshError($error);
            }
        };

        // Test various SSH error patterns
        $sshErrors = [
            'kex_exchange_identification: read: Connection reset by peer',
            'Connection refused',
            'Connection timed out',
            'ssh_exchange_identification: Connection closed by remote host',
            'Broken pipe',
            'No route to host',
            'Network is unreachable',
        ];

        foreach ($sshErrors as $error) {
            $this->assertTrue(
                $handler->test_is_retryable_ssh_error($error),
                "Failed to identify as retryable: $error"
            );
        }
    }

    public function test_non_ssh_errors_are_not_retryable()
    {
        $handler = new class
        {
            use SshRetryable;

            // Make methods public for testing
            public function test_is_retryable_ssh_error($error)
            {
                return $this->isRetryableSshError($error);
            }
        };

        // Test non-SSH errors
        $nonSshErrors = [
            'Command not found',
            'Permission denied',
            'File not found',
            'Syntax error',
            'Invalid argument',
        ];

        foreach ($nonSshErrors as $error) {
            $this->assertFalse(
                $handler->test_is_retryable_ssh_error($error),
                "Incorrectly identified as retryable: $error"
            );
        }
    }

    public function test_exponential_backoff_calculation()
    {
        $handler = new class
        {
            use SshRetryable;

            // Make method public for testing
            public function test_calculate_retry_delay($attempt)
            {
                return $this->calculateRetryDelay($attempt);
            }
        };

        // Test with default config values
        config(['constants.ssh.retry_base_delay' => 2]);
        config(['constants.ssh.retry_max_delay' => 30]);
        config(['constants.ssh.retry_multiplier' => 2]);

        // Attempt 0: 2 seconds
        $this->assertEquals(2, $handler->test_calculate_retry_delay(0));

        // Attempt 1: 4 seconds
        $this->assertEquals(4, $handler->test_calculate_retry_delay(1));

        // Attempt 2: 8 seconds
        $this->assertEquals(8, $handler->test_calculate_retry_delay(2));

        // Attempt 3: 16 seconds
        $this->assertEquals(16, $handler->test_calculate_retry_delay(3));

        // Attempt 4: Should be capped at 30 seconds
        $this->assertEquals(30, $handler->test_calculate_retry_delay(4));

        // Attempt 5: Should still be capped at 30 seconds
        $this->assertEquals(30, $handler->test_calculate_retry_delay(5));
    }

    public function test_retry_succeeds_after_failures()
    {
        $attemptCount = 0;

        config(['constants.ssh.max_retries' => 3]);

        // Simulate a function that fails twice then succeeds using the public static method
        $result = SshRetryHandler::retry(
            function () use (&$attemptCount) {
                $attemptCount++;
                if ($attemptCount < 3) {
                    throw new \RuntimeException('kex_exchange_identification: Connection reset by peer');
                }

                return 'success';
            },
            ['test' => 'retry_test'],
            true
        );

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attemptCount);
    }

    public function test_retry_fails_after_max_attempts()
    {
        $attemptCount = 0;

        config(['constants.ssh.max_retries' => 3]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection reset by peer');

        // Simulate a function that always fails using the public static method
        SshRetryHandler::retry(
            function () use (&$attemptCount) {
                $attemptCount++;
                throw new \RuntimeException('Connection reset by peer');
            },
            ['test' => 'retry_test'],
            true
        );
    }

    public function test_non_retryable_errors_fail_immediately()
    {
        $attemptCount = 0;

        config(['constants.ssh.max_retries' => 3]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command not found');

        try {
            // Simulate a non-retryable error using the public static method
            SshRetryHandler::retry(
                function () use (&$attemptCount) {
                    $attemptCount++;
                    throw new \RuntimeException('Command not found');
                },
                ['test' => 'non_retryable_test'],
                true
            );
        } catch (\RuntimeException $e) {
            // Should only attempt once since it's not retryable
            $this->assertEquals(1, $attemptCount);
            throw $e;
        }
    }
}
