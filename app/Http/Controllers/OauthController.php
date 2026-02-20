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
            $settings = instanceSettings();

            if (! $user) {
                // New user: allow registration if either the general registration
                // switch OR the dedicated OAuth registration switch is on.
                // This lets admins disable password-based self-registration while
                // still allowing trusted OAuth providers to onboard users.
                $oauthRegistrationAllowed = $settings->is_oauth_registration_enabled ?? true;
                $generalRegistrationAllowed = $settings->is_registration_enabled;

                if (! $oauthRegistrationAllowed && ! $generalRegistrationAllowed) {
                    abort(403, 'Registration is disabled');
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                ]);
            }

            // If oauth_force_only is enabled, mark the user so they cannot use
            // a local password.  The flag is stored on the user record and
            // enforced by the Fortify authentication pipeline.
            if ($settings->oauth_force_only ?? false) {
                $user->forceFill(['oauth_force_only' => true])->save();
            }

            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
