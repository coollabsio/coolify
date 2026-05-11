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
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                if (User::count() === 0) {
                    $user = (new User)->forceFill([
                        'id' => 0,
                        'name' => $oauthUser->name,
                        'email' => $oauthUser->email,
                        'oauth_provider' => $provider,
                        'is_oauth_only' => $settings->is_oauth_only_auth_enabled,
                    ]);
                    $user->save();

                    $settings->is_registration_enabled = false;
                    $settings->save();
                } else {
                    $user = User::create([
                        'name' => $oauthUser->name,
                        'email' => $oauthUser->email,
                        'oauth_provider' => $provider,
                        'is_oauth_only' => $settings->is_oauth_only_auth_enabled,
                    ]);
                }
            } elseif ($user->oauth_provider === $provider && instanceSettings()->is_oauth_only_auth_enabled && ! $user->is_oauth_only) {
                $user->update(['is_oauth_only' => true]);
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
