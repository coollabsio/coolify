<?php

namespace App\Helpers;

use App\Traits\SshRetryable;

/**
 * Helper class to use SshRetryable trait in non-class contexts
 */
class SshRetryHandler
{
    use SshRetryable;

    /**
     * Static method to get a singleton instance
     */
    public static function instance(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self;
        }

        return $instance;
    }

    /**
     * Convenience static method for retry execution
     */
    public static function retry(callable $callback, array $context = [], bool $throwError = true)
    {
        return self::instance()->executeWithSshRetry($callback, $context, $throwError);
    }
}
