<?php

use App\Models\ApplicationPreview;
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

it('uses compact application domains with unified settings and a form save button', function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    $this->application->update([
        'fqdn' => 'https://first.example.com,https://second.example.com',
        'ports_exposes' => '3000,8069',
        'redirect' => 'both',
    ]);
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
        ->fill('#editingDomainParts-port', '8069')
        ->fill('#editingDomainParts-path', '/blog')
        ->click('[id^="application-domain-indexing-"][id$="-trigger"]')
        ->click('Noindex')
        ->assertDontSee('Search engine indexing updated.')
        ->click('Regenerate hostname')
        ->screenshot(filename: 'application-domain-unified-settings')
        ->click('Save')
        ->assertDontSee('Domain settings')
        ->assertSee('Internal port 8069')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-domains-compact');

    $savedDomain = str($this->application->fresh()->fqdn)->before(',')->toString();
    expect($savedDomain)->not->toContain('first.example.com')
        ->and($this->application->fresh()->domain_port_overrides)->toHaveKey($savedDomain, 8069)
        ->and($this->application->fresh()->noindexDomains()->all())->toContain($savedDomain);

    $page->click('[aria-label="Settings for https://second.example.com"]')
        ->fill('#editingDomainParts-path', '/discard')
        ->click('[aria-label="Close"]:visible')
        ->assertDontSee('Domain settings')
        ->click('[aria-label="Settings for https://second.example.com"]')
        ->assertValue('#editingDomainParts-path', '')
        ->click('[aria-label="Close"]:visible')
        ->click('[wire\\:key="domain-row-'.md5($savedDomain.'|').'"] [aria-label="Remove domain"]')
        ->assertSee('Remove domain?')
        ->click('button:has([x-text="step2ButtonText"]):visible')
        ->assertDontSee($savedDomain)
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
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-compose-domain-settings');

    expect(json_decode($this->application->fresh()->docker_compose_domains, true)['web.api']['redirect'])->toBe('both');
    $page->click('Save')
        ->assertDontSee('Domain settings')
        ->assertSee('https://www.web.example.com')
        ->screenshot(filename: 'application-compose-domain-overview');

    expect(json_decode($this->application->fresh()->docker_compose_domains, true)['web.api']['redirect'])->toBe('www');
});

it('uses compact preview domains and opens only the selected preview settings', function (bool $isCompose) {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => false]);
    Cache::forget('instance_settings');
    if ($isCompose) {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'compose_parsing_version' => '3',
            'docker_compose_raw' => "services:\n  web.api:\n    image: nginx:alpine\n    expose:\n      - '8080'\n",
            'docker_compose_domains' => json_encode(['web.api' => ['domain' => 'https://production.example.com']]),
        ]);
    }
    foreach ([101, 102] as $number) {
        ApplicationPreview::create([
            'application_id' => $this->application->id,
            'pull_request_id' => $number,
            'pull_request_html_url' => "https://example.com/pull/{$number}",
            'fqdn' => $isCompose ? null : "https://preview-{$number}.example.com",
            'docker_compose_domains' => $isCompose ? json_encode(['web.api' => ['domain' => "https://preview-{$number}.example.com"]]) : null,
        ]);
    }
    loginAndSkipBoarding();
    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application).'/preview-deployments';
    $page = visit($url);
    $page->click('Accept and close')
        ->click('[aria-label="Settings for https://preview-101.example.com"]')
        ->assertSee('Domain settings')
        ->assertVisible('[aria-label="Search indexing blocked"] >> nth=0');
    expect($page->script("[...document.querySelectorAll('[data-preview-domain-dialog]')].filter(el => el.getClientRects().length > 0).length"))->toBe(1);
    $page->fill('#editingDomainParts-host:visible', 'renamed-preview.example.com')
        ->assertSee('Regenerate hostname')
        ->screenshot(filename: 'preview-domain-unified-settings')
        ->click('[data-preview-domain-dialog]:visible button:has-text("Save")')
        ->assertDontSee('Domain settings')
        ->assertSee('https://renamed-preview.example.com')
        ->assertSee('https://preview-102.example.com')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'preview-domains-compact');
    $page->click('[aria-label="Settings for https://preview-102.example.com"]')
        ->assertSee('Domain settings')
        ->fill('#editingDomainParts-path:visible', '/discard')
        ->screenshot(filename: 'preview-domain-before-reset')
        ->click('[data-preview-domain-dialog]:visible [aria-label="Close"]')
        ->assertDontSee('Domain settings')
        ->click('[aria-label="Settings for https://preview-102.example.com"]')
        ->assertValue('#editingDomainParts-path:visible', '')
        ->click('[data-preview-domain-dialog]:visible [aria-label="Close"]')
        ->resize(390, 844)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'preview-domains-mobile');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with(['regular application' => false, 'Compose application' => true]);

it('declares the compact preview domain layout and shared save bar', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/preview-domains.blade.php'));
    expect($view)->toContain('service-domains-overview-grid')
        ->toContain('service-domain-mobile-summary')
        ->toContain('Domain routing summary')
        ->toContain('<x-unsaved-bar action="updateDomain"')
        ->toContain('$event.detail.previewId');
});
