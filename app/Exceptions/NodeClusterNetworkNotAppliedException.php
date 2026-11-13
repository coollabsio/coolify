<?php

namespace App\Exceptions;

use App\Models\Node;
use RuntimeException;

/**
 * The reachable Nodes of a cluster did not apply a network revision: one Node failed, or the
 * wait ended. Callers that need their own text build it from the Node and the names.
 */
class NodeClusterNetworkNotAppliedException extends RuntimeException
{
    /** @param  list<string>  $pendingNames  The Nodes that had not applied the revision. */
    public function __construct(string $message, public readonly ?Node $failedNode = null, public readonly array $pendingNames = [])
    {
        parent::__construct($message);
    }
}
