<?php

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Server::flushIdentityMap());

afterEach(fn () => Server::flushIdentityMap());

it('saves the HTTPS credential from the source page without showing the token again', function () {
    $stack = seedBrowserResourceStack();
    $application = createBrowserApplication($stack, [
        'name' => 'Azure App',
        'git_repository' => 'https://dev.azure.com/org/project/_git/app',
        'git_branch' => 'main',
    ]);
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($stack['project'], $stack['environment'], $application).'/source');
    $page->assertSee('HTTPS credentials')
        ->type('gitHttpsUsername', 'deploy-bot')
        ->type('gitHttpsPassword', 'browser-pat-token')
        ->click('button:visible:has-text("Save changes")')
        ->assertSee('Remove credentials')
        ->assertValue('input[name=gitHttpsPassword]', '')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-git-https-credential');

    expect($application->fresh()->git_https_password)->toBe('browser-pat-token')
        ->and($application->fresh()->git_https_username)->toBe('deploy-bot');
});
