<?php

namespace App\Http\Controllers;

use App\Models\OauthSetting;
use App\Services\Auth\OauthIdentityIssuer;
use App\Services\Auth\OauthLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthController extends Controller
{
    public const string PASSWORD_CONFIRMATION_SESSION_KEY = 'oauth.password_confirmation';

    public function redirect(string $provider)
    {
        $oauthSetting = $this->enabledProvider($provider);
        $socialiteProvider = get_socialite_provider($oauthSetting->provider);

        return $socialiteProvider->redirect();
    }

    /**
     * Start confirming a destructive action by re-authenticating the logged-in
     * user through an OAuth provider the user has a linked identity with.
     */
    public function confirm(Request $request, string $provider)
    {
        $user = $request->user();
        $oauthSetting = $this->enabledProvider($provider);
        $linkedIdentities = $user->oauthIdentities()->where('provider', $oauthSetting->provider);
        if (! $oauthSetting->isOidc()) {
            $linkedIdentities->where('issuer', OauthIdentityIssuer::forSetting($oauthSetting));
        }
        if (! $linkedIdentities->exists()) {
            abort(403, 'No identity from this provider is linked to your account.');
        }

        $request->session()->put(self::PASSWORD_CONFIRMATION_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'provider' => $oauthSetting->provider,
            'return_to' => $this->sameOriginUrl(url()->previous()),
        ]);

        $socialiteProvider = get_socialite_provider($oauthSetting->provider);
        $parameters = socialite_reauthentication_parameters($oauthSetting);
        if ($parameters !== []) {
            $socialiteProvider->with($parameters);
        }

        return $socialiteProvider->redirect();
    }

    public function callback(Request $request, string $provider, OauthLoginService $oauthLoginService)
    {
        $pendingConfirmation = $request->session()->pull(self::PASSWORD_CONFIRMATION_SESSION_KEY);
        if (is_array($pendingConfirmation) && $request->user()) {
            return $this->confirmCallback($request, $provider, $pendingConfirmation, $oauthLoginService);
        }

        try {
            $oauthSetting = $this->enabledProvider($provider);
            $oauthUser = get_socialite_provider($oauthSetting->provider)->user();
            $user = $oauthLoginService->login($oauthSetting->provider, $oauthUser, $oauthSetting);

            if ($oauthLoginService->requiresTwoFactorChallenge($user)) {
                return redirect()->route('two-factor.login');
            }

            return redirect('/');
        } catch (\Exception $e) {
            $this->logCallbackFailure($provider, $e);

            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    /**
     * Complete an OAuth confirmation. The returned identity must already be
     * linked to the logged-in user; the session is never switched to another
     * user and no identity is linked or updated.
     *
     * @param  array{user_id?: mixed, provider?: mixed, return_to?: mixed}  $pendingConfirmation
     */
    private function confirmCallback(Request $request, string $provider, array $pendingConfirmation, OauthLoginService $oauthLoginService): RedirectResponse
    {
        $user = $request->user();

        try {
            $oauthSetting = $this->enabledProvider($provider);
            if (
                data_get($pendingConfirmation, 'user_id') !== $user->getKey()
                || data_get($pendingConfirmation, 'provider') !== $oauthSetting->provider
            ) {
                throw new HttpException(403, 'OAuth confirmation does not match the current session');
            }

            $oauthUser = get_socialite_provider($oauthSetting->provider)->user();
            if (! $oauthLoginService->identityBelongsToUser($user, $oauthSetting, $oauthUser)) {
                throw new HttpException(403, 'OAuth identity is not linked to the current user');
            }
        } catch (\Exception $e) {
            auditLog('auth.oauth.password_confirmation_failed', [
                'provider' => $provider,
                'actor_id' => $user->getKey(),
                'actor_email' => $user->email,
                'exception_class' => $e::class,
            ], 'warning');
            Log::warning('OAuth password confirmation failed.', [
                'provider' => $provider,
                'user_id' => $user->getKey(),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            abort(403, 'Confirmation failed. Sign in with the account linked to your Coolify user.');
        }

        $request->session()->passwordConfirmed();
        auditLog('auth.oauth.password_confirmed', [
            'provider' => $oauthSetting->provider,
            'actor_id' => $user->getKey(),
            'actor_email' => $user->email,
        ]);

        return redirect()->to($this->sameOriginUrl(data_get($pendingConfirmation, 'return_to')));
    }

    private function sameOriginUrl(mixed $url): string
    {
        $home = url('/');
        if (! is_string($url) || ($url !== $home && ! str_starts_with($url, $home.'/') && ! str_starts_with($url, $home.'?'))) {
            return $home;
        }

        return $url;
    }

    private function logCallbackFailure(string $provider, \Throwable $exception): void
    {
        auditLog('auth.oauth.callback_failed', [
            'provider' => $provider,
            'exception_class' => $exception::class,
            'reason' => $exception instanceof HttpException ? 'access_denied' : 'callback_error',
        ], 'warning');
        Log::error('OAuth callback failed.', [
            'provider' => $provider,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'request_error' => request()->query('error'),
            'request_error_description' => request()->query('error_description'),
            'has_code' => request()->query->has('code'),
            'has_state' => request()->query->has('state'),
            'ip' => request()->ip(),
            'exception' => $exception,
        ]);
    }

    private function enabledProvider(string $provider): OauthSetting
    {
        $oauthSetting = OauthSetting::where('provider', $provider)->first();
        if (! $oauthSetting || ! $oauthSetting->enabled || ! $oauthSetting->couldBeEnabled()) {
            throw new HttpException(403, 'OAuth provider is not enabled');
        }

        return $oauthSetting;
    }
}
