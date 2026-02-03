<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
                $user = $this->handleUserRegistration($oauthUser, $provider);
            } else {
                // Update OAuth provider info for existing user if not already set
                $this->updateExistingUserOauthInfo($user, $oauthUser, $provider);
            }

            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    /**
     * Handle user registration via OAuth.
     * Checks if general registration or OAuth registration is enabled.
     */
    private function handleUserRegistration($oauthUser, string $provider): User
    {
        $settings = instanceSettings();

        // Check if general registration or OAuth-specific registration is enabled
        $canRegister = $settings->is_registration_enabled || $settings->is_oauth_registration_enabled;

        if (! $canRegister) {
            abort(403, 'Registration is disabled');
        }

        // Rate limit OAuth registrations
        $rateLimitKey = 'oauth-registration:'.request()->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            abort(429, 'Too many registration attempts. Please try again later.');
        }
        RateLimiter::hit($rateLimitKey, 3600); // 1 hour

        // Verify email exists from OAuth provider
        if (empty($oauthUser->email)) {
            abort(400, 'Email not provided by OAuth provider. Please ensure your OAuth account has a verified email.');
        }

        // Create user with OAuth provider info
        return User::create([
            'name' => $oauthUser->name ?? $oauthUser->nickname ?? 'User',
            'email' => $oauthUser->email,
            'oauth_provider' => $provider,
            'oauth_id' => $oauthUser->id,
            'email_verified_at' => now(), // OAuth emails are considered verified
        ]);
    }

    /**
     * Update existing user's OAuth information if not already set.
     */
    private function updateExistingUserOauthInfo(User $user, $oauthUser, string $provider): void
    {
        // Only update if user doesn't have OAuth info set
        if (empty($user->oauth_provider)) {
            $user->update([
                'oauth_provider' => $provider,
                'oauth_id' => $oauthUser->id,
            ]);
        }
    }
}
