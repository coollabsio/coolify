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
        abort_unless(OauthSetting::where('provider', $provider)->where('enabled', true)->exists(), 404);

        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider)
    {
        try {
            abort_unless(OauthSetting::where('provider', $provider)->where('enabled', true)->exists(), 404);

            $oauthUser = get_socialite_provider($provider)->user();
            $user = User::whereEmail($oauthUser->email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                if (User::count() === 0) {
                    $user = (new User)->forceFill([
                        'id' => 0,
                        'name' => $oauthUser->name,
                        'email' => $oauthUser->email,
                        'email_verified_at' => now(),
                    ]);
                    $user->save();
                } else {
                    $user = User::create([
                        'name' => $oauthUser->name,
                        'email' => $oauthUser->email,
                        'email_verified_at' => now(),
                    ]);
                }
            }
            Auth::login($user);
            $team = $user->teams()->where('personal_team', true)->first() ?? $user->recreate_personal_team();
            session(['currentTeam' => $team]);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
