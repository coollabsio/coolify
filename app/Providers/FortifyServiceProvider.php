<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\PasskeyConfirmationResponse;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);

        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                // First user (root) will be redirected to /settings instead of / on registration.
                if ($request->user()->currentTeam->id === 0) {
                    return redirect()->route('settings.index');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        configurePasskeyRelyingParty(
            passkeyRelyingPartyId(),
            passkeyAllowedOrigins(),
        );

        config([
            'fortify.passkeys.user_handle_secret' => config('app.key'),
            'fortify.passkeys.timeout' => 60000,
            'passkeys.user_handle_secret' => config('app.key'),
            'passkeys.timeout' => 60000,
        ]);

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::registerView(function () {
            $isFirstUser = User::count() === 0;

            $settings = instanceSettings();
            if (! $settings->is_registration_enabled) {
                return redirect()->route('login');
            }

            return view('auth.register', [
                'isFirstUser' => $isFirstUser,
            ]);
        });

        Fortify::loginView(function () {
            $settings = instanceSettings();
            $enabled_oauth_providers = OauthSetting::where('enabled', true)->get();
            $users = User::count();
            if ($users == 0) {
                // If there are no users, redirect to registration
                return redirect()->route('register');
            }

            return view('auth.login', [
                'is_registration_enabled' => $settings->is_registration_enabled,
                'enabled_oauth_providers' => $enabled_oauth_providers,
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $email = strtolower($request->email);
            $user = User::where('email', $email)->with('teams')->first();
            if (
                $user &&
                Hash::check($request->password, $user->password)
            ) {
                $user->updated_at = now();
                $user->save();

                return $user;
            }
        });
        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });
        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', ['request' => $request]);
        });
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::confirmPasswordView(function () {
            return view('auth.confirm-password', [
                'hasPasskeys' => auth()->user()?->passkeys()->exists() ?? false,
            ]);
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });

        RateLimiter::for('force-password-reset', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()->id);
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            // Use real client IP (not spoofable forwarded headers)
            $realIp = $request->server('REMOTE_ADDR') ?? $request->ip();

            return Limit::perMinute(5)->by($realIp);
        });

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->email;
            // Use email + real client IP (not spoofable forwarded headers)
            // server('REMOTE_ADDR') gives the actual connecting IP before proxy headers
            $realIp = $request->server('REMOTE_ADDR') ?? $request->ip();

            return Limit::perMinute(5)->by($email.'|'.$realIp);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $realIp = $request->server('REMOTE_ADDR') ?? $request->ip();

            return Limit::perMinute(5)->by($realIp);
        });
    }
}
