<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        // Force session save before redirect to prevent race condition with Redis
        // This ensures the session is fully persisted before the browser follows the redirect
        session()->save();

        return redirect()->intended(config('fortify.home'));
    }
}
