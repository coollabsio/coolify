<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class CheckoutUnavailableException extends Exception
{
    public function __construct(
        string $message = '',
        public readonly ?string $billingPortalUrl = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
