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
                // Allow registration if:
                // 1. General registration is enabled, OR
                // 2. OAuth registration is specifically enabled (even when general registration is disabled)
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                // Determine if this user should be marked as OAuth-only
                // User is OAuth-only if the global setting is enabled or if general registration is disabled
                // (meaning they can only exist because of OAuth)
                $isOauthOnly = $settings->is_oauth_only_login_forced ||
                    (! $settings->is_registration_enabled && $settings->is_oauth_registration_enabled);

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $oauthUser->email,
                    'is_oauth_only' => $isOauthOnly,
                ]);
            }

            // Handle team invitation for OAuth login (same as password login)
            $invitation = \App\Models\TeamInvitation::whereEmail($user->email)->first();
            if ($invitation && $invitation->isValid()) {
                // User is logging in for the first time after being invited
                // Attach them to the invited team if not already attached
                if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                    $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
                }
                session(['currentTeam' => $invitation->team]);
                $invitation->delete();
            } else {
                // Normal login - use personal team
                $currentTeam = $user->teams->firstWhere('personal_team', true);
                if (! $currentTeam) {
                    $currentTeam = $user->recreate_personal_team();
                }
                session(['currentTeam' => $currentTeam]);
            }

            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
