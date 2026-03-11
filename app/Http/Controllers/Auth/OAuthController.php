<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    public function redirect(string $provider, Request $request)
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, Request $request)
    {
        $this->validateProvider($provider);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors(['oauth' => 'OAuth authentication failed. Please try again.']);
        }

        $settings = instanceSettings();

        $existingUser = User::where('email', strtolower($oauthUser->getEmail()))->first();

        if ($existingUser) {
            Auth::login($existingUser, true);
            $team = $existingUser->currentTeam();
            if (! $team) {
                $team = $existingUser->teams()->first();
            }
            session(['currentTeam' => $team]);

            return redirect()->intended(RouteServiceProvider::HOME ?? '/dashboard');
        }

        // No existing user — check registration permissions
        if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
            return redirect()->route('login')->withErrors(['oauth' => 'Registration is disabled. Please contact your administrator.']);
        }

        // Create new user from OAuth
        $newUser = User::create([
            'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? explode('@', $oauthUser->getEmail())[0],
            'email' => strtolower($oauthUser->getEmail()),
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
        ]);

        $team = $newUser->teams()->first();
        session(['currentTeam' => $newUser->currentTeam = $team]);

        Auth::login($newUser, true);

        return redirect()->intended('/dashboard');
    }

    protected function validateProvider(string $provider): void
    {
        $allowedProviders = ['github', 'gitlab', 'google', 'bitbucket', 'azure'];

        if (! in_array($provider, $allowedProviders)) {
            abort(404, 'OAuth provider not supported.');
        }
    }
}
