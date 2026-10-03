<?php

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
