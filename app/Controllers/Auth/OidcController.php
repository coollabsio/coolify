<?php

namespace App\Controllers\Auth;

use App\Services\OAuth\OidcProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OidcController extends Controller
{
    public function redirect(OidcProvider $provider)
    {
        return $provider->redirect();
    }

    public function callback(OidcProvider $provider, Request $request)
    {
        $user = $provider->user();
        if ($user) {
            Auth::login($user);
            return redirect()->intended();
        }
        return redirect()->route('login');
    }
}