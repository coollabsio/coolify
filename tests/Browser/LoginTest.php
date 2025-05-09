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
     *
     * @throws Throwable
     */
    public function test_login()
    {
        $this->browse(callback: function (Browser $browser) {
            $browser->loginWithRootUser()
                ->assertPathIs('/')
                ->assertSee('Dashboard');
        });
    }
}
