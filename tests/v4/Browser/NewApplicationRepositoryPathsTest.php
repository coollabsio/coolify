<?php

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restores the default compose file when the field is cleared', function () {
    $stack = seedBrowserResourceStack();
    loginAndSkipBoarding();

    $page = visit("/project/{$stack['project']->uuid}/environment/{$stack['environment']->uuid}/new?type=public&destination={$stack['destination']->uuid}");

    $page->fill('repository_url', 'https://gitlab.com/example/monorepo.git')
        ->click('Check repository')
        ->assertSee('Build configuration');

    selectListboxOption($page, 'build_pack', 'Docker Compose');

    $page->clear("input[placeholder='/docker-compose.yaml']")
        ->keys("input[placeholder='/docker-compose.yaml']", 'Tab')
        ->assertValue("input[placeholder='/docker-compose.yaml']", '/docker-compose.yaml')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'new-application-compose-file-cleared');
});

it('warns when the compose file repeats the base directory and creates the application with the suggested fix', function () {
    $stack = seedBrowserResourceStack();
    loginAndSkipBoarding();

    $page = visit("/project/{$stack['project']->uuid}/environment/{$stack['environment']->uuid}/new?type=public&destination={$stack['destination']->uuid}");

    $page->fill('repository_url', 'https://gitlab.com/example/monorepo.git')
        ->click('Check repository')
        ->assertSee('Build configuration');

    selectListboxOption($page, 'build_pack', 'Docker Compose');

    $page->fill("input[placeholder='/']", 'apps/api/')
        ->fill("input[placeholder='/docker-compose.yaml']", 'apps/api/compose.yaml')
        ->assertSee('Base directory is repeated')
        ->assertSee('/apps/api/apps/api/compose.yaml')
        ->screenshot(filename: 'new-application-compose-file-repeats-base-directory')
        ->click('button:has-text("Use /compose.yaml")')
        ->assertDontSee('Base directory is repeated')
        ->assertSee('/apps/api/compose.yaml')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'new-application-compose-file-fixed');

    submitLivewireForm($page);

    $application = Application::query()->where('git_repository', 'https://gitlab.com/example/monorepo.git')->sole();
    expect($application->base_directory)->toBe('/apps/api')
        ->and($application->docker_compose_location)->toBe('/compose.yaml');
});
