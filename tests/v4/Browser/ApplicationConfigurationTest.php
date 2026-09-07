<?php

use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('keeps the PR suffix listbox visible outside the volumes table while scrolling', function () {
    foreach (range(1, 8) as $index) {
        LocalPersistentVolume::create([
            'uuid' => (string) new Cuid2,
            'name' => $this->application->uuid.'-data-'.$index,
            'mount_path' => '/data/'.$index,
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
            'is_preview_suffix_enabled' => true,
        ]);
    }

    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    ).'/persistent-storage';

    $page = visit($url);
    $page->assertSee('PR suffix')
        ->assertSee('Add suffix')
        ->assertScript(<<<'JS'
            () => {
                const table = document.querySelector('.data-table');
                const trigger = document.querySelector('[id$="isPreviewSuffixEnabled-trigger"]');
                window.__volumeTableScrollHeight = table.scrollHeight;
                trigger.click();

                return true;
            }
            JS)
        ->wait(0.2)
        ->assertScript(<<<'JS'
            () => {
                const table = document.querySelector('.data-table');
                const panel = document.querySelector('[id$="isPreviewSuffixEnabled-panel"]');
                const beforeScroll = panel.getBoundingClientRect();
                window.scrollBy(0, 100);
                const afterScroll = panel.getBoundingClientRect();

                return panel.parentElement === document.body
                    && table.scrollHeight === window.__volumeTableScrollHeight
                    && window.scrollY > 0
                    && beforeScroll.top >= 0
                    && beforeScroll.bottom <= window.innerHeight
                    && afterScroll.top >= 0
                    && afterScroll.bottom <= window.innerHeight;
            }
            JS)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-persistent-storage-pr-suffix-listbox');
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

it('uses compact application domains with unified settings and a floating save bar', function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    $this->application->update(['fqdn' => 'https://first.example.com,https://second.example.com', 'redirect' => 'both']);
    loginAndSkipBoarding();
    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application).'/domains';
    $page = visit($url);
    $page->click('Accept and close')
        ->assertSee('Check all DNS')
        ->assertSee('Protocol redirect')
        ->assertSee('Search indexing')
        ->assertDontSee('Search engine indexing')
        ->fill('[aria-label="Search services or domains"]', 'missing.example.com')
        ->assertSee('No domains found')
        ->fill('[aria-label="Search services or domains"]', '')
        ->click('[aria-label="Settings for https://first.example.com"]')
        ->assertSee('Domain settings')
        ->assertValue('#editingDomainParts-host', 'first.example.com')
        ->assertMissing('.is-dirty [wire\\:click="updateDomain"]')
        ->fill('#editingDomainParts-path', '/blog')
        ->assertVisible('.is-dirty:not(.is-saving) [wire\\:click="updateDomain"]')
        ->click('[id^="application-domain-indexing-"][id$="-trigger"]')
        ->click('Noindex')
        ->assertSee('Search engine indexing updated.')
        ->assertVisible('.is-dirty:not(.is-saving) [wire\\:click="updateDomain"]')
        ->screenshot(filename: 'application-domain-unified-settings')
        ->click('[wire\\:click="updateDomain"]')
        ->assertDontSee('Domain settings')
        ->assertSee('https://first.example.com/blog')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-domains-compact');

    $page->click('[aria-label="Settings for https://second.example.com"]')
        ->fill('#editingDomainParts-path', '/discard')
        ->click('Reset')
        ->assertDontSee('Domain settings')
        ->click('[aria-label="Settings for https://second.example.com"]')
        ->assertValue('#editingDomainParts-path', '')
        ->click('[aria-label="Close"]:visible')
        ->click('[wire\\:key="domain-row-'.md5('https://first.example.com/blog|').'"] [aria-label="Remove domain"]')
        ->assertSee('Remove domain?')
        ->click('button:has([x-text="step2ButtonText"]):visible')
        ->assertDontSee('https://first.example.com/blog')
        ->click('[aria-label="Settings for https://second.example.com"]')
        ->assertValue('#editingDomainParts-host', 'second.example.com')
        ->click('[aria-label="Close"]:visible')
        ->resize(390, 844)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-domains-mobile');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('edits Compose application domain redirects in the unified settings dialog', function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web.api:\n    image: nginx:alpine\n    expose:\n      - '8080'\n",
        'docker_compose_domains' => json_encode(['web.api' => ['domain' => 'https://web.example.com', 'redirect' => 'both']]),
    ]);
    loginAndSkipBoarding();
    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application).'/domains';
    $page = visit($url);
    $page->click('Accept and close')
        ->assertSee('web.api')
        ->assertSee('Domain redirect')
        ->click('[aria-label="Settings for https://web.example.com"]')
        ->assertSee('Domain settings')
        ->click('[id^="application-domain-direction-"][id$="-trigger"]')
        ->click('Redirect to www')
        ->assertDontSee('Use a different port?')
        ->assertSee('Redirect updated for web.api.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-compose-domain-settings');

    expect(json_decode($this->application->fresh()->docker_compose_domains, true)['web.api']['redirect'])->toBe('www');
    $page->click('[aria-label="Close"]:visible')
        ->assertSee('https://www.web.example.com')
        ->screenshot(filename: 'application-compose-domain-overview');
});
