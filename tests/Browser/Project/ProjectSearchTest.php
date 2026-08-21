<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Throwable;

class ProjectSearchTest extends DuskTestCase
{
    /**
     * A basic test for the projects page.
     * Login with the test user and assert that the user is redirected to the projects page.
     *
     * @return void
     *
     * @throws Throwable
     */
    public function test_login()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginWithRootUser()
                ->visit('/projects')
                ->type('[x-model="search"]', 'joi43j4oi32j4o2')
                ->assertSee('No project found with the search term "joi43j4oi32j4o2".')
                ->screenshot('project-search-not-found');
        });
    }
}
