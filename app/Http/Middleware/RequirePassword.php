<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword as Middleware;

class RequirePassword extends Middleware
{
    /**
     * Users with a linked OAuth identity, or without a password, cannot confirm
     * a password they do not know. They follow the same rule as destructive
     * actions (see User::requiresPasswordConfirmation()).
     */
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null)
    {
        if ($request->user()?->requiresPasswordConfirmation() === false) {
            return false;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
