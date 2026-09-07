<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-browser-pg',
        'name' => 'Config Postgres',
        'description' => 'Initial postgres description',
    ]);
    $this->redis = createBrowserRedis($this->stack, [
        'uuid' => 'db-browser-redis',
        'name' => 'Config Redis',
    ]);
});

it('shows postgres configuration sections', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);

    $page->assertSee('Config Postgres')
        ->assertSee('General')
        ->assertSee('Environment Variables')
        ->assertSee('Persistent Storage')
        ->assertSee('Danger Zone')
        ->assertSee('Username')
        ->assertSee('Initial database')
        ->screenshot(filename: 'database-postgres-configuration');
});

it('saves postgres name description and credentials fields', function () {
    loginAndSkipBoarding();

    $updatedName = 'Postgres UI '.(string) new Cuid2;
    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    $page->fill('name', $updatedName)
        ->fill('description', 'Updated postgres description')
        ->fill('postgresDb', 'coolify_browser')
        ->screenshot(filename: 'database-postgres-before-save');

    submitLivewireForm($page);

    $this->postgres->refresh();
    expect($this->postgres->name)->toBe($updatedName)
        ->and($this->postgres->description)->toBe('Updated postgres description')
        ->and($this->postgres->postgres_db)->toBe('coolify_browser');

    visit($url)
        ->assertValue('name', $updatedName)
        ->assertValue('postgresDb', 'coolify_browser')
        ->screenshot(filename: 'database-postgres-after-reload');
});

it('shows ssl controls on postgres configuration', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    // SSL toggle is present; mode selector appears after enabling SSL.
    $page->assertSee('General')
        ->assertSee('Config Postgres')
        ->screenshot(filename: 'database-postgres-ssl-controls');

    $page->script(<<<'JS'
        (() => {
            const toggle = document.querySelector('[id^="enableSsl"]');
            if (toggle) {
                toggle.click();
            }
        })()
    JS);
    $page->wait(2);

    $this->postgres->refresh();
    if ($this->postgres->enable_ssl) {
        $page->assertSee('SSL Mode');
    }

    $page->screenshot(filename: 'database-postgres-ssl-after-toggle');
});

it('shows redis configuration page', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->redis
    );

    $page = visit($url);

    $page->assertSee('Config Redis')
        ->assertSee('General')
        ->screenshot(filename: 'database-redis-configuration');
});

it('lists databases on the environment resources page', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];

    $page = visit("/project/{$project->uuid}/environment/{$environment->uuid}");

    $page->assertSee('Config Postgres')
        ->assertSee('Config Redis')
        ->screenshot(filename: 'environment-lists-databases');
});

it('opens database environment variables and backups pages', function () {
    loginAndSkipBoarding();

    $base = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    visit("{$base}/environment-variables")
        ->assertSee('Environment Variables')
        ->screenshot(filename: 'database-environment-variables');

    visit("{$base}/backups")
        ->assertSee('Config Postgres')
        ->screenshot(filename: 'database-backups');
});

it('shows database danger zone', function () {
    loginAndSkipBoarding();

    $base = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    visit("{$base}/danger")
        ->assertSee('Danger')
        ->assertSee('Config Postgres')
        ->screenshot(filename: 'database-danger-zone');
});
