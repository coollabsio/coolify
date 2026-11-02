<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
});

it('updates the project breadcrumb after the project is renamed', function () {
    loginAndSkipBoarding();

    $page = visit(route('project.edit', ['project_uuid' => $this->stack['project']->uuid]));
    $page->assertSeeIn('button[title="Switch project"]', 'Browser Project')
        ->fill('name', 'Renamed Project');

    submitLivewireForm($page);

    $page->assertSeeIn('button[title="Switch project"]', 'Renamed Project')
        ->screenshot(filename: 'breadcrumb-project-renamed');
});

it('updates the resource breadcrumb after the database is renamed', function () {
    $postgres = createBrowserPostgresql($this->stack, ['name' => 'Old Postgres']);
    loginAndSkipBoarding();

    $page = visit(databaseConfigurationUrl($this->stack['project'], $this->stack['environment'], $postgres));
    $page->assertSeeIn('button[title="Switch resource"]', 'Old Postgres')
        ->fill('name', 'Renamed Postgres');

    submitLivewireForm($page);

    $page->assertSeeIn('button[title="Switch resource"]', 'Renamed Postgres')
        ->screenshot(filename: 'breadcrumb-database-renamed');
});
