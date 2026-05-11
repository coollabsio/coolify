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
                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $email,
                    'is_oauth_user' => true,
                ]);
            } elseif (! $user->hasPassword() && ! $user->is_oauth_user) {
                $user->update(['is_oauth_user' => true]);
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
