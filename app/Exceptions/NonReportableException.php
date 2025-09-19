<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception that should not be reported to Sentry or other error tracking services.
 * Use this for known, expected errors that don't require external tracking.
 */
class NonReportableException extends Exception
{
    /**
     * Create a new non-reportable exception instance.
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
