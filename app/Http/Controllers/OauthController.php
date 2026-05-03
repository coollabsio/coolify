<?php

namespace App\Http\Controllers;

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthController extends Controller
{
    public function redirect(string $provider)
    {
        $this->ensureProviderIsEnabled($provider);

        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $this->ensureProviderIsEnabled($provider);

            $oauthUser = get_socialite_provider($provider)->user();
            $email = data_get($oauthUser, 'email');
            abort_if(blank($email), 403, 'Email is required');

            $user = User::whereEmail($email)->first();
            $settings = instanceSettings();
            if (! $user) {
                if (! $settings->is_registration_enabled && ! $settings->is_oauth_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                $user = $this->createOauthUser($oauthUser, $provider, $settings);
            } elseif ($user->oauth_provider !== $provider) {
                $user->forceFill([
                    'oauth_provider' => $provider,
                ])->save();
            }
            Auth::login($user);
            $this->setCurrentTeam($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    private function ensureProviderIsEnabled(string $provider): void
    {
        abort_unless(
            OauthSetting::where('provider', $provider)->where('enabled', true)->exists(),
            404
        );
    }

    private function createOauthUser(object $oauthUser, string $provider, InstanceSettings $settings): User
    {
        $email = data_get($oauthUser, 'email');
        $name = data_get($oauthUser, 'name') ?: $email;

        if (User::count() === 0) {
            $user = (new User)->forceFill([
                'id' => 0,
                'name' => $name,
                'email' => $email,
                'oauth_provider' => $provider,
            ]);
            $user->save();

            $settings->is_registration_enabled = false;
            $settings->save();

            return $user;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->forceFill([
            'oauth_provider' => $provider,
        ])->save();

        return $user;
    }

    private function setCurrentTeam(User $user): void
    {
        $user->loadMissing('teams');
        $currentTeam = $user->teams->firstWhere('personal_team', true)
            ?? $user->teams->first()
            ?? $user->recreate_personal_team();

        $user->currentTeam = $currentTeam;
        session(['currentTeam' => $currentTeam]);
    }
}
