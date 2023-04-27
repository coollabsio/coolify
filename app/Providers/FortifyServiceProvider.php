<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::registerView(function () {
            $settings = InstanceSettings::find(0);
            if (!$settings->is_registration_enabled) {
                return redirect()->route('login');
            }
            return view('auth.register');
        });

        Fortify::loginView(function () {
            $settings = InstanceSettings::find(0);
            return view('auth.login', [
                'is_registration_enabled' => $settings->is_registration_enabled
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->with('teams')->first();
            if (
                $user &&
                Hash::check($request->password, $user->password)
            ) {
                session(['currentTeam' => $user->currentTeam = $user->teams->firstWhere('personal_team', true)]);
                return $user;
            }
        });
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->email;

            return Limit::perMinute(5)->by($email . $request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
