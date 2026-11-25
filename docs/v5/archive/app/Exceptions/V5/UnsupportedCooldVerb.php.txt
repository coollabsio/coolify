<?php

namespace App\Exceptions\V5;

use RuntimeException;

/**
 * The per-node coold agent does not implement the dispatched verb. Flux
 * rejects these before they reach the node, so callers can degrade
 * gracefully instead of treating the miss as an operational failure.
 */
class UnsupportedCooldVerb extends RuntimeException
{
    public function __construct(public readonly string $verb, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "The node's coold agent does not support the {$verb} verb.");
    }
}
