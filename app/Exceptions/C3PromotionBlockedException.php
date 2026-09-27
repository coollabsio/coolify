<?php

namespace App\Exceptions;

/**
 * Connect3 fork: promotion pre-flight failed. Nothing has been changed.
 */
class C3PromotionBlockedException extends \RuntimeException
{
    /**
     * @param  array<string, array{status: string, message: string}>  $dnsResults
     */
    public function __construct(string $message, public array $dnsResults = [])
    {
        parent::__construct($message);
    }
}
