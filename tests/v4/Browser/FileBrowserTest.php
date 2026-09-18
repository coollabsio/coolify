<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-browser-files',
        'name' => 'Files Postgres',
        'status' => 'exited',
    ]);
});

it('shows the Files tab and a stopped state for a stopped container', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    ).'/files';

    $page = visit($url);

    $page->assertSee('Files')
        ->assertSee('Start the container to browse its files')
        ->screenshot(filename: 'file-browser-stopped');
});
