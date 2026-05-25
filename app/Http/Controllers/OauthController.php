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
                $user = $this->createUserFromOauth($oauthUser->name, $email);
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    private function createUserFromOauth(?string $name, string $email): User
    {
        $userData = [
            'name' => filled($name) ? $name : $email,
            'email' => $email,
        ];

        if (User::count() === 0) {
            $user = (new User)->forceFill([
                'id' => 0,
                ...$userData,
            ]);
            $user->save();

            $settings = instanceSettings();
            $settings->is_registration_enabled = false;
            $settings->save();

            return $user;
        }

        return User::create($userData);
    }
}
