<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('keeps domain settings out of the overview and supports editing and removal', function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    $this->serviceApplication->update([
        'fqdn' => 'https://long-public-domain-for-the-service.example.com,https://second.example.com',
        'domain_port_overrides' => ['https://long-public-domain-for-the-service.example.com' => 8080],
    ]);
    loginAndSkipBoarding();

    $url = serviceConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->service).'/domains';
    $page = visit($url);
    $page->click('Accept and close')
        ->assertSee('Check all DNS')
        ->assertVisible('[aria-label="Internal port 8080"]')
        ->assertDontSee('Search engine indexing')
        ->assertDontSee('Remove domain')
        ->screenshot(filename: 'service-domains-overview');

    expect($page->script("document.querySelector('.data-table-row.service-domains-overview-grid').getBoundingClientRect().height"))->toBeLessThanOrEqual(48);
    expect($page->script("getComputedStyle(document.querySelector('.data-table-row.service-domains-overview-grid')).gridTemplateColumns.split(' ').length"))->toBe(7);

    $page->click('[aria-label="Settings for https://long-public-domain-for-the-service.example.com"]')
        ->assertSee('Domain settings')
        ->assertValue('#editingDomainParts-host', 'long-public-domain-for-the-service.example.com')
        ->assertValue('#editingDomainParts-port', '8080')
        ->assertDontSee('Edit address and port')
        ->assertDontSee('Save address')
        ->assertMissing('.is-dirty [wire\\:click="updateDomain"]')
        ->assertSee('Search engine indexing')
        ->screenshot(filename: 'service-domain-settings');

    expect($page->script(<<<'JS'
        (() => {
            const indexing = document.querySelector('[id^="service-domain-indexing-"][id$="-trigger"]').getBoundingClientRect();
            const redirect = document.querySelector('[id^="service-domain-direction-"][id$="-trigger"]').getBoundingClientRect();
            return Math.abs(indexing.top - redirect.top) < 2 && redirect.left > indexing.right;
        })()
    JS))->toBeTrue();

    $page->fill('#editingDomainParts-port', '80')
        ->assertVisible('.is-dirty:not(.is-saving) [wire\\:click="updateDomain"]')
        ->screenshot(filename: 'service-domain-unsaved-changes');

    $domainKey = hash('sha256', 'https://long-public-domain-for-the-service.example.com|'.$this->serviceApplication->id);
    $page->click('#service-domain-indexing-'.$this->serviceApplication->id.'-'.$domainKey.'-trigger')
        ->click('Noindex')
        ->screenshot(filename: 'service-domain-indexing-saved')
        ->assertSee('Domain settings')
        ->assertVisible('[aria-label="Search indexing blocked"]')
        ->assertVisible('.is-dirty:not(.is-saving) [wire\\:click="updateDomain"]')
        ->assertNoJavaScriptErrors();

    expect($this->serviceApplication->fresh()->isDomainNoindexed('https://long-public-domain-for-the-service.example.com'))->toBeTrue();

    $page->click('[wire\\:click="updateDomain"]')
        ->assertDontSee('Domain settings')
        ->assertVisible('[aria-label="Internal port 80"] >> nth=0');

    $page->click('[wire\\:key="svc-domain-'.$this->serviceApplication->id.'-'.md5('https://long-public-domain-for-the-service.example.com').'"] [aria-label="Remove domain"]')
        ->assertSee('Remove domain?')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'service-domain-removal-confirmation')
        ->click('button:has([x-text="step2ButtonText"]):visible')
        ->assertDontSee('https://long-public-domain-for-the-service.example.com')
        ->assertSee('1 domain across 1 service')
        ->click('[aria-label="Settings for https://second.example.com"]')
        ->assertSee('www redirect')
        ->assertValue('#editingDomainParts-host', 'second.example.com')
        ->click('[aria-label="Close"]:visible')
        ->assertDontSee('Domain settings')
        ->assertNoJavaScriptErrors();

    $page->click('[aria-label="Settings for https://second.example.com"]')
        ->fill('#editingDomainParts-path', '/discard-this')
        ->assertVisible('.is-dirty:not(.is-saving) [wire\\:click="updateDomain"]')
        ->click('Reset')
        ->assertDontSee('Domain settings')
        ->click('[aria-label="Settings for https://second.example.com"]')
        ->assertValue('#editingDomainParts-path', '')
        ->assertMissing('.is-dirty [wire\\:click="updateDomain"]')
        ->click('[aria-label="Close"]:visible')
        ->assertDontSee('Domain settings')
        ->assertNoJavaScriptErrors();

    $page->script("document.querySelectorAll('[aria-label=\"Dismiss\"]').forEach(button => button.click()); document.documentElement.classList.remove('dark');");
    $page->screenshot(filename: 'service-domains-light');
    $page->resize(390, 844)
        ->assertSee('https://second.example.com')
        ->assertVisible('[aria-label="Settings for https://second.example.com"]');
    $page->script("document.getElementById('service-domains-section').scrollIntoView(); window.scrollBy(0, -80);");
    $page->screenshot(filename: 'service-domains-mobile');

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('inherits the internal port when enabling the www redirect without a warning', function (?int $override) {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    $this->service->update([
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      - SERVICE_FQDN_WEB_80\n  api:\n    image: httpd:alpine\n",
    ]);
    $this->serviceApplication->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both', 'domain_port_overrides' => $override === null ? null : ['https://web.example.com' => $override]]);
    loginAndSkipBoarding();

    $url = serviceConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->service).'/domains';
    $page = visit($url);
    $page->click('Accept and close')
        ->click('[aria-label="Settings for https://web.example.com"]')
        ->click('[id^="service-domain-direction-"][id$="-trigger"]')
        ->click('Redirect to www')
        ->assertDontSee('Use a different port?')
        ->assertSee('Redirect updated.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'service-redirect-port-'.($override ?? 80));

    expect($this->serviceApplication->fresh()->redirect)->toBe('www')
        ->and($this->serviceApplication->fresh()->domain_port_overrides['https://www.web.example.com'] ?? null)->toBe($override);

    $page->navigate($url)
        ->click('[aria-label="Settings for https://web.example.com"]')
        ->assertSee('Redirect to www')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'service-redirect-persisted-'.($override ?? 80));
})->with([null, 8080]);
