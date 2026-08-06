<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-config',
        'name' => 'Config App',
        'ports_exposes' => '3000',
        'custom_docker_run_options' => null,
    ]);
});

it('shows application configuration sections and navigation', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);

    $page->assertSee('Config App')
        ->assertSee('General')
        ->assertSee('Environment Variables')
        ->assertSee('Danger Zone')
        ->assertSee('Application details')
        ->assertSee('Internal access')
        ->assertSee('Docker network')
        ->assertSee('Build pipeline')
        ->screenshot(filename: 'application-configuration-overview');
});

it('saves application name description and ports from the general form', function () {
    loginAndSkipBoarding();

    $updatedName = 'App UI '.(string) new Cuid2;
    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    $page->assertSee('General')
        ->fill('name', $updatedName)
        ->fill('description', 'Updated via browser test')
        ->fill('portsExposes', '8080')
        ->screenshot(filename: 'application-general-before-save');

    submitLivewireForm($page);

    $page->assertValue('name', $updatedName)
        ->screenshot(filename: 'application-general-after-save');

    $this->application->refresh();
    expect($this->application->name)->toBe($updatedName)
        ->and($this->application->description)->toBe('Updated via browser test')
        ->and($this->application->ports_exposes)->toBe('8080');

    $reloaded = visit($url);
    $reloaded->assertValue('name', $updatedName)
        ->assertValue('description', 'Updated via browser test')
        ->assertValue('portsExposes', '8080')
        ->screenshot(filename: 'application-general-reloaded');
});

it('saves custom docker run options from the UI', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );
    $options = '--hostname=browser-app --cap-add=SYS_ADMIN';

    $page = visit($url);
    $page->fill('customDockerRunOptions', $options);
    submitLivewireForm($page);

    $this->application->refresh();
    expect($this->application->custom_docker_run_options)->toBe($options);

    $page->assertValue('customDockerRunOptions', $options)
        ->screenshot(filename: 'application-docker-run-options');
});

it('opens environment variables page for the application', function () {
    loginAndSkipBoarding();

    $base = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit("{$base}/environment-variables");

    $page->assertSee('Environment Variables')
        ->assertSee('Config App')
        ->screenshot(filename: 'application-environment-variables');
});

it('opens advanced and healthcheck configuration pages', function () {
    loginAndSkipBoarding();

    $base = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    visit("{$base}/advanced")
        ->assertSee('Config App')
        ->screenshot(filename: 'application-advanced');

    visit("{$base}/healthcheck")
        ->assertSee('Config App')
        ->screenshot(filename: 'application-healthcheck');
});

it('lists the application on the environment resources page', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];

    $page = visit("/project/{$project->uuid}/environment/{$environment->uuid}");

    $page->assertSee('Config App')
        ->screenshot(filename: 'environment-lists-application');
});

it('shows danger zone for application deletion', function () {
    loginAndSkipBoarding();

    $base = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit("{$base}/danger");

    $page->assertSee('Danger')
        ->assertSee('Config App')
        ->screenshot(filename: 'application-danger-zone');
});
