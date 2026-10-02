<?php

namespace App\Services\Auth;

use App\Auth\Oidc\OidcUser;
use App\Exceptions\OauthLoginException;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
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

        // Choose the team like the password login: restore the last active team,
        // or the sole team. A multi-team user without a valid stored choice gets
        // no session team, so DecideWhatToDoWithUser shows the team selection.
        $user->unsetRelation('teams');
        $team = $user->resolveStoredTeam();
        if (! $team && $user->teams->isEmpty()) {
            $team = $user->recreate_personal_team();
        }
        if ($team) {
            session(['currentTeam' => $team]);
        } else {
            session()->forget('currentTeam');
        }

        if ($this->requiresTwoFactorChallenge($user)) {
            Auth::logout();
            session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);
            TwoFactorAuthenticationChallenged::dispatch($user);

            return $user;
        }

        Auth::login($user);
        auditLog('auth.user.oauth_login_succeeded', [
            'team_id' => $team?->id,
            'resource' => 'user',
            'user_name' => $user->name,
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'provider' => $provider,
        ]);

        return $user;
    }

    public function requiresTwoFactorChallenge(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function oidcIssuerAndSubject(object $oauthUser): array
    {
        $issuer = $oauthUser instanceof OidcUser && filled($oauthUser->issuer)
            ? $oauthUser->issuer
            : data_get($oauthUser->user, 'iss');
        $subject = $oauthUser instanceof OidcUser && filled($oauthUser->subject)
            ? $oauthUser->subject
            : data_get($oauthUser->user, 'sub', $oauthUser->id);

        return [$issuer, $subject];
    }

    private function resolveOauthUser(object $oauthUser, OauthSetting $oauthSetting, string $email): User
    {
        $provider = $oauthSetting->provider;
        $providerUserId = $oauthUser->id ?? null;
        if (
            (! is_string($providerUserId) && ! is_int($providerUserId))
            || (is_string($providerUserId) && trim($providerUserId) === '')
        ) {
            throw new HttpException(403, 'OAuth provider did not return a valid user ID');
        }
        $providerUserId = (string) $providerUserId;
        $rawClaims = is_array($oauthUser->user ?? null) ? $oauthUser->user : [];

        if ($provider === 'google' && ! $this->isInGoogleWorkspace($oauthSetting->tenant, data_get($rawClaims, 'hd'))) {
            throw new HttpException(403, 'Google account is not in the configured Workspace');
        }

        $issuer = OauthIdentityIssuer::forSetting($oauthSetting);
        if ($issuer === null) {
            throw new HttpException(403, 'OAuth provider instance is not configured');
        }

        $identityKey = [
            'provider' => $provider,
            'issuer' => $issuer,
            'provider_user_id' => $providerUserId,
        ];

        try {
            return DB::transaction(function () use ($oauthUser, $oauthSetting, $email, $provider, $issuer, $providerUserId, $rawClaims, $identityKey): User {
                $identity = OauthIdentity::where($identityKey)->first();

                if ($identity) {
                    $identity->update([
                        'email' => $email,
                        'raw_claims' => $rawClaims,
                        'last_login_at' => now(),
                    ]);

                    return $identity->user;
                }

                $user = User::whereEmail($email)->first();

                // Linking to an existing account needs a verified email. A new account
                // follows the registration settings, like password registration, which
                // does not verify the email either. Password-less users from before OAuth
                // identities existed (OAuth matched by email only) link their first
                // identity without a verification claim. This check runs before the
                // linked-account check, so an unverified email cannot reveal one.
                $isPreUpgradeOauthUser = $user?->created_before_oauth_identities === true && ! $user->hasPassword();
                if ($user && ! $isPreUpgradeOauthUser && ! $this->hasVerifiedEmail($provider, $rawClaims, $email)) {
                    throw new OauthLoginException('OAuth provider did not verify the email address', 'auth.failed.oauth_email_unverified');
                }

                if ($user?->oauthIdentities()->exists()) {
                    throw new OauthLoginException('OAuth identity cannot be linked to this account', 'auth.failed.oauth_already_linked');
                }

                if (! $user) {
                    if (! $oauthSetting->allowsUserCreation()) {
                        throw new OauthLoginException('Registration is disabled', 'auth.registration_disabled');
                    }

                    $user = $this->createUser($oauthUser->name ?: $email, $email, $oauthSetting);
                }

                OauthIdentity::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'issuer' => $issuer,
                    'provider_user_id' => $providerUserId,
                    'email' => $email,
                    'raw_claims' => $rawClaims,
                    'last_login_at' => now(),
                ]);

                if ($user->created_before_oauth_identities) {
                    $user->forceFill(['created_before_oauth_identities' => false])->save();
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return OauthIdentity::where($identityKey)->first()?->user ?? throw $exception;
        }
    }

    /**
     * An empty hosted domain allows every Google account. A "*" allows any
     * Workspace account. Domains are compared without case and spaces.
     */
    private function isInGoogleWorkspace(?string $hostedDomain, mixed $hdClaim): bool
    {
        $hostedDomain = strtolower(trim((string) $hostedDomain));
        if ($hostedDomain === '') {
            return true;
        }

        $hdClaim = is_string($hdClaim) ? strtolower(trim($hdClaim)) : '';
        if ($hdClaim === '') {
            return false;
        }

        return $hostedDomain === '*' || $hdClaim === $hostedDomain;
    }

    /**
     * GitHub and Bitbucket select only verified primary email addresses in
     * their Socialite providers. Microsoft Graph has no verification flag, but
     * Entra ID only issues a user principal name in a domain the tenant
     * verified, so only that name is trusted. GitLab confirms the primary
     * email of an account. Other providers must return an explicit boolean
     * verification claim in the raw provider response.
     *
     * @param  array<string, mixed>  $rawClaims
     */
    private function hasVerifiedEmail(string $provider, array $rawClaims, string $email): bool
    {
        return match ($provider) {
            'github', 'bitbucket' => true,
            'discord' => data_get($rawClaims, 'verified') === true,
            'azure' => strtolower((string) data_get($rawClaims, 'userPrincipalName')) === $email,
            'gitlab' => filled(data_get($rawClaims, 'confirmed_at')),
            default => data_get($rawClaims, 'email_verified') === true,
        };
    }

    private function resolveOidcUser(object $oauthUser, OauthSetting $oauthSetting, string $email): User
    {
        [$issuer, $subject] = $this->oidcIssuerAndSubject($oauthUser);
        $emailVerified = ($oauthUser instanceof OidcUser && $oauthUser->emailVerified)
            || data_get($oauthUser->user, 'email_verified') === true;

        if (! is_string($issuer) || $issuer === '' || ! is_string($subject) || $subject === '') {
            throw new HttpException(403, 'OIDC provider did not return issuer and subject claims');
        }

        if ($oauthSetting->require_email_verified && ! $emailVerified) {
            throw new OauthLoginException('OIDC provider did not verify the email address', 'auth.failed.oauth_email_unverified');
        }

        $rawClaims = is_array($oauthUser->user ?? null) ? $oauthUser->user : [];

        $identityKey = [
            'provider' => 'oidc',
            'issuer' => $issuer,
            'provider_user_id' => $subject,
        ];

        try {
            return DB::transaction(function () use ($oauthUser, $oauthSetting, $email, $issuer, $subject, $emailVerified, $rawClaims, $identityKey): User {
                $identity = OauthIdentity::where($identityKey)->first();

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
                    throw new OauthLoginException('OIDC provider must verify the email address before linking to an existing account', 'auth.failed.oauth_email_unverified');
                }

                if ($user?->oauthIdentities()->exists()) {
                    throw new OauthLoginException('OAuth identity cannot be linked to this account', 'auth.failed.oauth_already_linked');
                }

                if (! $user) {
                    if (! $oauthSetting->allowsUserCreation()) {
                        throw new OauthLoginException('Registration is disabled', 'auth.registration_disabled');
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
        } catch (UniqueConstraintViolationException $exception) {
            return OauthIdentity::where($identityKey)->first()?->user ?? throw $exception;
        }
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
