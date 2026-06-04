<?php

namespace App\Http\Controllers;

use App\Models\OauthSetting;
use App\Services\Auth\OauthLoginService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthController extends Controller
{
    public function redirect(string $provider)
    {
        $oauthSetting = $this->enabledProvider($provider);
        $socialiteProvider = get_socialite_provider($oauthSetting->provider);

        return $socialiteProvider->redirect();
    }

    public function callback(string $provider, OauthLoginService $oauthLoginService)
    {
        try {
            $oauthSetting = $this->enabledProvider($provider);
            $oauthUser = get_socialite_provider($oauthSetting->provider)->user();
            $oauthLoginService->login($oauthSetting->provider, $oauthUser, $oauthSetting);

            return redirect('/');
        } catch (\Exception $e) {
            $this->logCallbackFailure($provider, $e);

            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }

    private function logCallbackFailure(string $provider, \Throwable $exception): void
    {
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
