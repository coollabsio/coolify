<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Throwable;

class LoginTest extends DuskTestCase
{
    /**
     * A basic test for the login page.
     * Login with the test user and assert that the user is redirected to the dashboard.
     *
     * @return void
     * @throws Throwable
     */
    public function testLogin()
    {
        $email = config('testing.dusk_test_email');
        $password = config('testing.dusk_test_password');
        $this->browse(function (Browser $browser) use ($password, $email) {
            $browser->visit('/login')
                ->type('email', $email)
                ->type('password', $password)
                ->press('Login')
                ->assertPathIs('/');
        });
    }
}
