<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSourceMapping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirect(string $provider)
    {
        $oauthSetting = OauthSetting::where('provider', $provider)->firstOrFail();
        if (! $oauthSetting->enabled) {
            return redirect('/login')->with('error', 'OAuth provider is not enabled.');
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $oauthSetting = OauthSetting::where('provider', $provider)->firstOrFail();
            if (! $oauthSetting->enabled) {
                return redirect('/login')->with('error', 'OAuth provider is not enabled.');
            }

            $socialiteUser = Socialite::driver($provider)->user();
            $instanceSettings = InstanceSettings::get();

            // Check if user already exists via OAuth source mapping
            $userSourceMapping = UserSourceMapping::where('provider', $provider)
                ->where('provider_id', $socialiteUser->getId())
                ->first();

            if ($userSourceMapping) {
                // Existing OAuth user — always allow login
                $user = $userSourceMapping->user;
                Auth::login($user, true);

                return redirect('/');
            }

            // Check if user exists by email
            $existingUser = User::where('email', $socialiteUser->getEmail())->first();

            if ($existingUser) {
                // Link existing account to OAuth provider
                UserSourceMapping::create([
                    'user_id' => $existingUser->id,
                    'provider' => $provider,
                    'provider_id' => $socialiteUser->getId(),
                ]);
                Auth::login($existingUser, true);

                return redirect('/');
            }

            // New user — check registration permissions
            $generalRegistrationEnabled = $instanceSettings->is_registration_enabled ?? false;
            $oauthRegistrationEnabled = $instanceSettings->allow_oauth_registration ?? false;

            if (! $generalRegistrationEnabled && ! $oauthRegistrationEnabled) {
                return redirect('/login')->with('error', 'Registration is disabled. Please contact your administrator.');
            }

            // Create new user via OAuth
            $newUser = User::create([
                'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? explode('@', $socialiteUser->getEmail())[0],
                'email' => $socialiteUser->getEmail(),
                'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
            ]);

            // Create default team for new user
            $team = Team::create([
                'name' => $newUser->name."'s Team",
                'personal_team' => true,
                'show_boarding' => true,
            ]);
            $newUser->teams()->attach($team, ['role' => 'owner']);

            // Map OAuth source
            UserSourceMapping::create([
                'user_id' => $newUser->id,
                'provider' => $provider,
                'provider_id' => $socialiteUser->getId(),
            ]);

            Auth::login($newUser, true);

            return redirect('/');
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'OAuth authentication failed: '.$e->getMessage());
        }
    }
}