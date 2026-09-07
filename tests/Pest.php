<?php

use App\Models\Server;
use Illuminate\Support\Once;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser', 'v5/Browser');

/*
|--------------------------------------------------------------------------
| Shared Helpers
|--------------------------------------------------------------------------
|
| Helper functions shared across multiple test files.
|
*/

require_once __DIR__.'/Support/BrowserTestHelpers.php';

function remoteOutputSource(string $path): string
{
    $fixturePath = dirname(__DIR__).'/'.$path;

    if (! is_readable($fixturePath)) {
        throw new RuntimeException("Unable to read source fixture: {$fixturePath}");
    }

    $source = file_get_contents($fixturePath);

    if ($source === false) {
        throw new RuntimeException("Unable to read source fixture: {$fixturePath}");
    }

    return $source;
}

/*
|--------------------------------------------------------------------------
| Test Hooks
|--------------------------------------------------------------------------
|
| Global hooks that run before/after each test.
|
*/
beforeEach(function () {
    // Flush the Once memoization cache to ensure tests get fresh data
    Once::flush();

    // Flush the Server identity map cache to ensure tests get fresh data
    Server::flushIdentityMap();

    // Browser Livewire actions often dispatch events; the Soketi host is not
    // resolvable from host-side Pest runs (docker DNS name coolify-realtime).
    config(['broadcasting.default' => 'null']);
});

function loginAndSkipBoarding(string $email = 'test@example.com', string $password = 'password'): mixed
{
    $page = visit('/login')
        ->fill('email', $email)
        ->fill('password', $password)
        ->click('Login')
        ->wait(1.5);

    // First-login root users land on onboarding; skip when the control exists.
    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('button, a, [role="button"]'));
            const skip = candidates.find((el) => (el.textContent || '').trim().toLowerCase() === 'skip setup');
            if (skip) {
                skip.click();
            }
        })()
    JS);
    $page->wait(1.5);

    return $page;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }
