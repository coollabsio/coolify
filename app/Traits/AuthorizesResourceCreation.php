<?php

namespace App\Traits;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

trait AuthorizesResourceCreation
{
    use AuthorizesRequests;

    /**
     * Authorize creation of all supported resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeResourceCreation(): void
    {
        $this->authorize('createAnyResource');
    }
}
