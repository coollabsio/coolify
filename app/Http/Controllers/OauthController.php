<?php

namespace App\Http\Controllers;

use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthController extends Controller
{
    public function redirect(string $provider)
    {
        $oauthSetting = OauthSetting::firstWhere('provider', $provider);
        if (! $oauthSetting?->enabled) {
            abort(403, 'OAuth provider is disabled');
        }

        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $oauthSetting = OauthSetting::firstWhere('provider', $provider);
            if (! $oauthSetting?->enabled) {
                abort(403, 'OAuth provider is disabled');
            }

            $oauthUser = get_socialite_provider($provider)->user();
            $email = trim((string) $oauthUser->email);
            if ($email === '') {
                abort(403, 'OAuth provider did not return an email address');
            }
            $email = strtolower($email);
            $user = User::whereEmail($email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled && ! $oauthSetting->allow_registration) {
                    abort(403, 'Registration is disabled');
                }

                $name = trim((string) ($oauthUser->name ?? ''));

                $user = User::create([
                    'name' => $name !== '' ? $name : str($email)->before('@')->toString(),
                    'email' => $email,
                    'oauth_provider' => $provider,
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
