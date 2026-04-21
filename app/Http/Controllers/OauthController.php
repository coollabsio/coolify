<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                // Allow OAuth self-registration when either general registration
                // OR OAuth-only self-registration is enabled.
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                    'oauth_provider' => $provider,
                ]);
            } elseif (empty($user->oauth_provider)) {
                // Stamp existing accounts with the provider on first OAuth login,
                // so that "OAuth only" enforcement applies consistently.
                $user->forceFill(['oauth_provider' => $provider])->save();
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
