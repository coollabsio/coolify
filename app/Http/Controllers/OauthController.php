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
                // OAuth signup is allowed when either:
                //   (a) general self-registration is enabled, or
                //   (b) the admin has explicitly opted in to OAuth-only
                //       signups via allow_oauth_when_registration_disabled.
                // This decouples password-based registration from OAuth-
                // based registration so an instance can run in "OAuth-only"
                // mode (e.g. Authentik, Google Workspace) without leaving
                // a password form open to the public internet.
                $oauthSignupAllowed = $settings->is_registration_enabled
                    || $settings->allow_oauth_when_registration_disabled;
                if (! $oauthSignupAllowed) {
                    abort(403, 'Registration is disabled');
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                ]);
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
