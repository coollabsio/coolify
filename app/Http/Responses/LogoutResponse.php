<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        // Force session save before redirect to prevent race condition with Redis
        // This ensures the new session state is fully persisted before the browser follows the redirect
        session()->save();

        return redirect('/');
    }
}
