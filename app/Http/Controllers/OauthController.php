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
            $email = trim((string) $oauthUser->email);
            if ($email === '') {
                abort(403, 'OAuth provider did not return an email address');
            }
            $email = strtolower($email);
            $user = User::whereEmail($email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                if (User::count() === 0) {
                    $user = (new User)->forceFill([
                        'id' => 0,
                        'name' => $oauthUser->name,
                        'email' => $email,
                    ]);
                    $user->save();
                    $settings->is_registration_enabled = false;
                    $settings->save();
                } else {
                    $user = User::create([
                        'name' => $oauthUser->name,
                        'email' => $email,
                    ]);
                }

                if (isCloud()) {
                    $user->sendVerificationEmail();
                } else {
                    $user->markEmailAsVerified();
                }
            }

            $team = $user->teams()->first();
            session(['currentTeam' => $user->currentTeam = $team]);
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
