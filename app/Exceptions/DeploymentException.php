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
    private bool $messageAlreadyLogged = false;

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

    /**
     * Create an exception for a message that is already a visible line in the deployment log,
     * so the failure handler does not show the same explanation again.
     */
    public static function alreadyLogged(string $message, int $code = 0, ?\Throwable $previous = null): static
    {
        $exception = new static($message, $code, $previous);
        $exception->messageAlreadyLogged = true;

        return $exception;
    }

    public function isMessageAlreadyLogged(): bool
    {
        return $this->messageAlreadyLogged;
    }
}
