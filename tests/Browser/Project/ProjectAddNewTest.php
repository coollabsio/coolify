<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Throwable;

class ProjectAddNewTest extends DuskTestCase
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
                ->pressAndWaitFor('+ Add', 1)
                ->assertSee('New Project')
                ->screenshot('project-add-new-1')
                ->type('name', 'Test Project')
                ->screenshot('project-add-new-2')
                ->press('Continue')
                ->assertSee('Test Project.')
                ->screenshot('project-add-new-3');
        });
    }
}
