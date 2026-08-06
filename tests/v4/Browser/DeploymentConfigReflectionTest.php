<?php

/**
 * Browser + domain assertions that configuration changes made in the UI
 * are persisted and reflected in generated Docker/Coolify deployment config.
 *
 * Full remote deploy against Docker is out of scope for pure browser tests;
 * these tests prove the deploy pipeline inputs update correctly after UI saves.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-deploy-config',
        'name' => 'Deploy Config App',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-browser-deploy-config',
        'name' => 'Deploy Config Postgres',
        'custom_docker_run_options' => null,
    ]);
});

it('reflects application UI docker run options in compose conversion used by deploy', function () {
    loginAndSkipBoarding();

    $hostname = 'deploy-cfg-'.Str::lower(Str::random(8));
    $options = "--hostname={$hostname} --cap-add=SYS_ADMIN --shm-size=128m";
    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    $page->fill('name', 'Deploy Config App Saved')
        ->fill('portsExposes', '4000')
        ->fill('customDockerRunOptions', $options)
        ->screenshot(filename: 'deploy-config-app-before-save');

    submitLivewireForm($page);

    $this->application->refresh();

    expect($this->application->name)->toBe('Deploy Config App Saved')
        ->and($this->application->ports_exposes)->toBe('4000')
        ->and($this->application->custom_docker_run_options)->toBe($options);

    $composeOptions = convertDockerRunToCompose($this->application->custom_docker_run_options);

    expect(data_get($composeOptions, 'hostname'))->toBe($hostname)
        ->and(data_get($composeOptions, 'cap_add'))->toContain('SYS_ADMIN')
        ->and(data_get($composeOptions, 'shm_size'))->toBe('128m');

    // Coolify configuration export includes ports; docker run options feed Start* compose merge.
    $configuration = $this->application->getConfigurationAsArray();
    expect($configuration)->toBeArray()
        ->and(data_get($configuration, 'domains.ports_exposes'))->toBe('4000');

    visit($url)
        ->assertValue('portsExposes', '4000')
        ->assertValue('customDockerRunOptions', $options)
        ->screenshot(filename: 'deploy-config-app-after-reload');
});

it('reflects database UI custom docker run options in compose conversion', function () {
    loginAndSkipBoarding();

    $hostname = 'pg-deploy-'.Str::lower(Str::random(8));
    $options = "--hostname={$hostname} --shm-size=256m";
    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    $page->fill('customDockerRunOptions', $options);
    submitLivewireForm($page);

    $this->postgres->refresh();
    expect($this->postgres->custom_docker_run_options)->toBe($options);

    $composeOptions = convertDockerRunToCompose($this->postgres->custom_docker_run_options);
    expect(data_get($composeOptions, 'hostname'))->toBe($hostname)
        ->and(data_get($composeOptions, 'shm_size'))->toBe('256m');

    visit($url)
        ->assertValue('customDockerRunOptions', $options)
        ->screenshot(filename: 'deploy-config-database-docker-options');
});

it('updates deploy-relevant fields used for configuration change detection', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $beforePorts = $this->application->ports_exposes;
    $beforeOptions = $this->application->custom_docker_run_options;

    $page = visit($url);
    $page->fill('portsExposes', '9090')
        ->fill('customDockerRunOptions', '--hostname=hash-check-app');
    submitLivewireForm($page);

    $this->application->refresh();

    expect($this->application->ports_exposes)->toBe('9090')
        ->and($this->application->custom_docker_run_options)->toBe('--hostname=hash-check-app')
        ->and($this->application->ports_exposes)->not->toBe($beforePorts)
        ->and($this->application->custom_docker_run_options)->not->toBe($beforeOptions);

    $composeOptions = convertDockerRunToCompose($this->application->custom_docker_run_options);
    expect(data_get($composeOptions, 'hostname'))->toBe('hash-check-app');

    $page->screenshot(filename: 'deploy-config-hash-inputs');
});
