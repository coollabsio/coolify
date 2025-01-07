<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class DuskServiceProvider extends ServiceProvider
{
    /**
     * Register Dusk's browser macros.
     */
    public function boot(): void
    {
        \Laravel\Dusk\Browser::macro('loginWithRootUser', function () {
            return $this->visit('/login')
                ->type('email', 'test@example.com')
                ->type('password', 'password')
                ->press('Login');
        });
    }
}
