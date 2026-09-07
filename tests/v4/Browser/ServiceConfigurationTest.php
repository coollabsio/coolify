<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $created = createBrowserService($this->stack, [
        'uuid' => 'svc-browser-config',
        'name' => 'Config Service',
        'description' => 'Compose stack for browser tests',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - '80'
  api:
    image: httpd:alpine
YAML,
    ]);
    $this->service = $created['service'];
    $this->serviceApplication = $created['serviceApplication'];
});

it('shows service configuration with compose resources', function () {
    loginAndSkipBoarding();

    $url = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->service
    );

    $page = visit($url);

    $page->assertSee('Config Service')
        ->assertSee('General')
        ->assertSee('Environment Variables')
        ->assertSee('Compose resources')
        ->assertSee('web')
        ->screenshot(filename: 'service-configuration-overview');
});

it('saves service name and description', function () {
    loginAndSkipBoarding();

    $updatedName = 'Service UI '.(string) new Cuid2;
    $url = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->service
    );

    $page = visit($url);
    $page->fill('name', $updatedName)
        ->fill('description', 'Updated service description')
        ->screenshot(filename: 'service-before-save');

    // StackForm Livewire component owns name/description save.
    submitLivewireForm($page, 'submit');
    $page->wait(2);

    $this->service->refresh();
    expect($this->service->name)->toBe($updatedName)
        ->and($this->service->description)->toBe('Updated service description');

    visit($url)
        ->assertValue('name', $updatedName)
        ->screenshot(filename: 'service-after-reload');
});

it('opens service environment variables domains and storages pages', function () {
    loginAndSkipBoarding();

    $base = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->service
    );

    visit("{$base}/environment-variables")
        ->assertSee('Environment Variables')
        ->screenshot(filename: 'service-environment-variables');

    visit("{$base}/domains")
        ->assertSee('Config Service')
        ->screenshot(filename: 'service-domains');

    visit("{$base}/storages")
        ->assertSee('Config Service')
        ->screenshot(filename: 'service-storages');
});

it('renders an automatically added redirect counterpart without reloading', function () {
    $this->serviceApplication->update([
        'fqdn' => 'https://web.example.com',
        'redirect' => 'both',
    ]);

    loginAndSkipBoarding();

    $base = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->service
    );

    $page = visit("{$base}/domains")
        ->assertSee('https://web.example.com');

    $selector = '#service-domain-redirect-'.$this->serviceApplication->id.'-trigger';
    $page->click($selector)
        ->click('Redirect to www')
        ->wait(3);

    $this->serviceApplication->refresh();
    expect($this->serviceApplication->redirect)->toBe('www');

    $page->assertSee('https://www.web.example.com')
        ->screenshot(filename: 'service-domain-redirect-counterpart');

    $page->script(<<<'JS'
        (() => {
            const domain = document.querySelector('a[title="https://www.web.example.com"]');
            domain.closest('.env-table-item').querySelector('[aria-label="Remove domain"]').click();
        })()
    JS);

    $page->wait(0.5)
        ->script(<<<'JS'
            (() => {
                const button = [...document.querySelectorAll('button')]
                    .find((candidate) => candidate.offsetParent && candidate.textContent.trim() === 'Remove domain');
                button.click();
            })()
        JS);

    $page->wait(3);

    $configuredCounterparts = $page->script(
        'document.querySelectorAll(\'a[title="https://www.web.example.com"]\').length'
    );

    expect($configuredCounterparts)->toBe(0);

    $page->screenshot(filename: 'service-domain-remove-without-reload');
});

it('opens compose stack application general settings', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];
    $service = $this->service;
    $stackUuid = $this->serviceApplication->uuid;

    $page = visit("/project/{$project->uuid}/environment/{$environment->uuid}/service/{$service->uuid}/{$stackUuid}");

    $page->assertSee('web')
        ->assertSee('General')
        ->screenshot(filename: 'service-stack-application-general');
});

it('lists the service on the environment resources page', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];

    visit("/project/{$project->uuid}/environment/{$environment->uuid}")
        ->assertSee('Config Service')
        ->screenshot(filename: 'environment-lists-service');
});

it('shows service danger zone', function () {
    loginAndSkipBoarding();

    $base = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->service
    );

    visit("{$base}/danger")
        ->assertSee('Danger')
        ->assertSee('Config Service')
        ->screenshot(filename: 'service-danger-zone');
});
