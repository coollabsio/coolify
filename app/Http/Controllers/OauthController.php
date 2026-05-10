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
                $user = $this->createOauthUser($provider, $oauthUser, $email);
            } elseif (! $user->hasPassword() && blank($user->oauth_provider)) {
                $user->forceFill(['oauth_provider' => $provider])->save();
            }
            Auth::login($user);
            $team = $user->teams()->where('personal_team', true)->first() ?? $user->teams()->first();
            if ($team) {
                session(['currentTeam' => $team]);
            }

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    private function createOauthUser(string $provider, object $oauthUser, string $email): User
    {
        $name = data_get($oauthUser, 'name') ?: data_get($oauthUser, 'nickname') ?: $email;

        if (User::count() === 0) {
            $user = (new User)->forceFill([
                'id' => 0,
                'name' => $name,
                'email' => $email,
                'oauth_provider' => $provider,
            ]);
            $user->save();

            $settings = instanceSettings();
            $settings->is_registration_enabled = false;
            $settings->save();

            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'oauth_provider' => $provider,
        ]);
    }
}
