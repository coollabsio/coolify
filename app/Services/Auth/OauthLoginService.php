<?php

namespace App\Services\Auth;

use App\Auth\Oidc\OidcUser;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthLoginService
{
    public function login(string $provider, object $oauthUser, OauthSetting $oauthSetting): User
    {
        $email = strtolower(trim((string) $oauthUser->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(403, 'OAuth provider did not return a valid email address');
        }

        $user = $provider === 'oidc'
            ? $this->resolveOidcUser($oauthUser, $oauthSetting, $email)
            : $this->resolveOauthUser($oauthUser, $oauthSetting, $email);

        Auth::login($user);
        $team = $user->currentTeam() ?? $user->teams()->first() ?? $user->recreate_personal_team();
        session(['currentTeam' => $user->currentTeam = $team]);

        return $user;
    }

    private function resolveOauthUser(object $oauthUser, OauthSetting $oauthSetting, string $email): User
    {
        $user = User::whereEmail($email)->first();
        if ($user) {
            return $user;
        }

        if (! $this->canCreateUser($oauthSetting)) {
            throw new HttpException(403, 'Registration is disabled');
        }

        return $this->createUser($oauthUser->name ?: $email, $email, $oauthSetting);
    }

    private function resolveOidcUser(object $oauthUser, OauthSetting $oauthSetting, string $email): User
    {
        $issuer = $oauthUser instanceof OidcUser && filled($oauthUser->issuer)
            ? $oauthUser->issuer
            : data_get($oauthUser->user, 'iss');
        $subject = $oauthUser instanceof OidcUser && filled($oauthUser->subject)
            ? $oauthUser->subject
            : data_get($oauthUser->user, 'sub', $oauthUser->id);
        $emailVerified = ($oauthUser instanceof OidcUser && $oauthUser->emailVerified)
            || data_get($oauthUser->user, 'email_verified') === true;

        if (! is_string($issuer) || $issuer === '' || ! is_string($subject) || $subject === '') {
            throw new HttpException(403, 'OIDC provider did not return issuer and subject claims');
        }

        if ($oauthSetting->require_email_verified && ! $emailVerified) {
            throw new HttpException(403, 'OIDC provider did not verify the email address');
        }

        $rawClaims = is_array($oauthUser->user ?? null) ? $oauthUser->user : [];

        return DB::transaction(function () use ($oauthUser, $oauthSetting, $email, $issuer, $subject, $emailVerified, $rawClaims) {
            $identity = OauthIdentity::where([
                'provider' => 'oidc',
                'issuer' => $issuer,
                'provider_user_id' => $subject,
            ])->first();

            if ($identity) {
                $identity->update([
                    'email' => $email,
                    'raw_claims' => $rawClaims,
                    'last_login_at' => now(),
                ]);

                return $identity->user;
            }

            $user = User::whereEmail($email)->first();

            // Linking a new OIDC identity to an existing local account by email
            // is account takeover unless the provider attests the email. This
            // guard is independent of the require_email_verified toggle, which
            // only governs the broader login flow.
            if ($user && ! $emailVerified) {
                throw new HttpException(403, 'OIDC provider must verify the email address before linking to an existing account');
            }

            if (! $user) {
                if (! $this->canCreateUser($oauthSetting)) {
                    throw new HttpException(403, 'Registration is disabled');
                }

                $user = $this->createUser($oauthUser->name ?: $email, $email, $oauthSetting);
            }

            OauthIdentity::create([
                'user_id' => $user->id,
                'provider' => 'oidc',
                'issuer' => $issuer,
                'provider_user_id' => $subject,
                'email' => $email,
                'raw_claims' => $rawClaims,
                'last_login_at' => now(),
            ]);

            return $user;
        });
    }

    private function canCreateUser(OauthSetting $oauthSetting): bool
    {
        return instanceSettings()->is_registration_enabled || $oauthSetting->allow_registration;
    }

    private function createUser(string $name, string $email, OauthSetting $oauthSetting): User
    {
        if (User::count() === 0) {
            $user = (new User)->forceFill([
                'id' => 0,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
            ]);
            $user->save();

            $team = $user->teams()->first() ?? Team::find(0);
            if ($team !== null && ! $user->teams()->where('team_id', $team->id)->exists()) {
                $user->teams()->attach($team, ['role' => 'owner']);
            }

            instanceSettings()->update(['is_registration_enabled' => false]);

            return $user;
        }

        if ($oauthSetting->auto_join_root_team) {
            return $this->createRootTeamOnlyUser($name, $email);
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
        ]);
    }

    private function createRootTeamOnlyUser(string $name, string $email): User
    {
        return DB::transaction(function () use ($name, $email) {
            $rootTeam = Team::find(0);
            if ($rootTeam === null) {
                throw new HttpException(403, 'Root team is not available for OAuth user provisioning');
            }

            $user = User::withoutEvents(fn () => User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
            ]));

            $user->teams()->attach($rootTeam, ['role' => 'member']);

            return $user;
        });
    }
}
