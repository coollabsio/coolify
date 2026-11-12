<?php

namespace App\Enums;

use App\Models\NodeWorkload;

/**
 * The two containers that a placement of a workload can have on a Node. A rolling deploy starts
 * the new container in the slot that does not run, then removes the old one (decision 0022).
 */
enum NodeContainerSlot: string
{
    case MAIN = 'main';
    case NEXT = 'next';

    public function containerName(NodeWorkload $workload): string
    {
        return 'coolify-'.$workload->uuid.'-'.$this->value;
    }

    public function other(): self
    {
        return match ($this) {
            self::MAIN => self::NEXT,
            self::NEXT => self::MAIN,
        };
    }

    /** The `node_workload_nodes` column with the workload address of this slot. */
    public function addressColumn(): string
    {
        return match ($this) {
            self::MAIN => 'container_ip',
            self::NEXT => 'next_container_ip',
        };
    }
}
