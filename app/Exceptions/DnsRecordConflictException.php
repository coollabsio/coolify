<?php

namespace App\Exceptions;

use RuntimeException;

class DnsRecordConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $providerRecordId,
        public readonly string $currentValue,
        public readonly string $proposedValue,
    ) {
        parent::__construct("DNS record already points to {$currentValue}.");
    }
}
