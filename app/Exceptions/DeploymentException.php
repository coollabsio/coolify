<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception for expected deployment failures caused by user/application errors.
 * These are not Coolify bugs and should not be logged to laravel.log.
 * Examples: Nixpacks detection failures, missing Dockerfiles, invalid configs, etc.
 */
class DeploymentException extends Exception
{
    /**
     * Create a new deployment exception instance.
     *
     * @param  string  $message
     * @param  int  $code
     */
    public function __construct($message = '', $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create from another exception, preserving its message and stack trace.
     */
    public static function fromException(\Throwable $exception): static
    {
        return new static($exception->getMessage(), $exception->getCode(), $exception);
    }
}
