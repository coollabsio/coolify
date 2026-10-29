<?php

namespace App\Traits;

use App\Models\Environment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

trait AuthorizesResourceCreation
{
    use AuthorizesRequests;

    /**
     * Authorize creation of all supported resources, in the environment when one is given.
     *
     * @throws AuthorizationException
     */
    protected function authorizeResourceCreation(?Environment $environment = null): void
    {
        $this->authorize('createAnyResource', [$environment]);
    }
}
