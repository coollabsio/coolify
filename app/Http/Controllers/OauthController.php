<?php

namespace App\Http\Controllers;

use App\Models\OauthUserLink;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

            $email = strtolower(trim((string) ($oauthUser->email ?? '')));
            $providerUserId = trim((string) ($oauthUser->id ?? ''));

            if ($email === '' || $providerUserId === '') {
                abort(403, 'OAuth provider did not return an identity');
            }

            if (! oauth_email_is_verified($provider, $oauthUser)) {
                abort(403, 'Email is not verified by the OAuth provider');
            }

            $intent = session()->pull('oauth.intent');
            $intentUserId = session()->pull('oauth.user_id');

            if ($intent === 'link') {
                return $this->handleLinkIntent($provider, $providerUserId, $intentUserId);
            }

            return $this->handleLogin($provider, $providerUserId, $email, $oauthUser);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors([__('auth.failed.callback')]);
        }
    }

    private function handleLinkIntent(string $provider, string $providerUserId, $intentUserId)
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $intentUserId) {
            abort(403, 'OAuth link session is invalid');
        }

        $existing = OauthUserLink::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing && (int) $existing->user_id !== (int) Auth::id()) {
            abort(403, 'This OAuth account is already linked to another user');
        }

        OauthUserLink::updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $providerUserId],
            ['user_id' => Auth::id()]
        );

        return redirect()->route('profile')->with('status', 'OAuth provider '.$provider.' linked successfully.');
    }

    private function handleLogin(string $provider, string $providerUserId, string $email, $oauthUser)
    {
        $link = OauthUserLink::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        $user = $link?->user;

        if (! $user) {
            $existing = User::whereEmail($email)->first();

            if ($existing) {
                if ($existing->hasPassword()) {
                    abort(403, 'An account with that email already exists. Sign in with your password and link this provider in your Profile.');
                }

                // Backwards compatibility: pre-patch deployments created OAuth-only users
                // (no password). Auto-link them on their next verified OAuth login so they
                // are not locked out.
                $existing->oauthLinks()->create([
                    'provider' => $provider,
                    'provider_user_id' => $providerUserId,
                ]);

                $user = $existing;
            } else {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                $user = DB::transaction(function () use ($oauthUser, $email, $provider, $providerUserId) {
                    $created = User::create([
                        'name' => $oauthUser->name ?? $email,
                        'email' => $email,
                    ]);
                    $created->oauthLinks()->create([
                        'provider' => $provider,
                        'provider_user_id' => $providerUserId,
                    ]);

                    return $created;
                });
            }
        }

        // 2FA is intentionally not enforced on the OAuth path — the IdP is trusted
        // to enforce MFA. Password-based login still routes through Fortify's 2FA
        // challenge.
        Auth::login($user);

        return redirect('/');
    }
}
