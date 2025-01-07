<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Dusk\Browser;

class DuskServiceProvider extends ServiceProvider
{
    /**
     * Register Dusk's browser macros.
     */
    public function boot(): void
    {
        Browser::macro('loginWithRootUser', function () {
            return $this->visit('/login')
                ->type('email', 'test@example.com')
                ->type('password', 'password')
                ->press('Login');
        });
    }
}
