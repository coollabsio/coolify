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
        OauthSetting::where('provider', $provider)
            ->where('enabled', true)
            ->firstOrFail();

        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $oauthSetting = OauthSetting::where('provider', $provider)
                ->where('enabled', true)
                ->firstOrFail();
            $oauthUser = get_socialite_provider($provider)->user();
            $user = User::whereEmail($oauthUser->email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled && ! $oauthSetting->allow_registration) {
                    abort(403, 'Registration is disabled');
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                ]);
            }
            if ($oauthSetting->force_oauth_only && ($user->hasPassword() || ! $user->oauth_only)) {
                $user->forceFill([
                    'password' => null,
                    'oauth_only' => true,
                ])->save();
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
