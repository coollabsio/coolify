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
            $email = strtolower(trim((string) data_get($oauthUser, 'email', '')));

            if (! filled($email)) {
                abort(422, 'OAuth provider did not return an email address.');
            }

            $user = User::whereEmail($email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                $name = trim((string) data_get($oauthUser, 'name', ''));
                if (! filled($name)) {
                    $name = str($email)->before('@')->value();
                }

                $user = User::create([
                    'name' => $name,
                    'email' => $email,
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
