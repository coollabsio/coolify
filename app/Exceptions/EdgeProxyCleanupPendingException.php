<?php

namespace App\Exceptions;

class EdgeProxyCleanupPendingException extends \RuntimeException
{
    public function __construct(
        public readonly string $resourceType,
        public readonly string $resourceUuid,
        public readonly array $failures
    ) {
        parent::__construct(sprintf(
            'Edge cleanup pending for %s %s: %s',
            $resourceType,
            $resourceUuid,
            implode(' | ', $failures)
        ));
    }
}
