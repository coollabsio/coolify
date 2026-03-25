<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class OauthController extends Controller
{
    public function redirect(string $provider)
    {
        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $oauthUser = get_socialite_provider($provider)->user();
            $user = User::whereEmail($oauthUser->email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    return redirect()->route('login')->withErrors([__('auth.registration_disabled')]);
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                ]);
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors([__('auth.failed.callback')]);
        }
    }
}
