<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by the remote process of a package update when it finishes (also when it fails).
 */
class ServerPackagesUpdateFinished
{
    use Dispatchable;

    /**
     * @param  array{server_uuid: string, packages: array<int, string>}  $data
     */
    public function __construct(public readonly array $data) {}
}
