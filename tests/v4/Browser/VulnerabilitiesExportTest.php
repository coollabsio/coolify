<?php

use App\Models\ImageScan;
use App\Models\ServerHostScan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
    $this->stack['server']->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-vulns',
        'name' => 'Vulns App',
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
    ]);

    $finding = fn (array $overrides) => [
        'id' => 'CVE-2024-0001', 'aliases' => [], 'severity' => 'high', 'package' => 'openssl', 'version' => '3.0.1',
        'type' => 'apk', 'artifactId' => 'a1', 'fixedIn' => '3.0.2', 'fixState' => 'fixed', 'kev' => false,
        'epss' => 0.2, 'risk' => 1.0, 'summary' => 'A bug.', 'layer' => 'app', ...$overrides,
    ];
    ImageScan::factory()->create([
        'server_id' => $this->stack['server']->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->getKey(),
        'image_ref' => 'nginx:1.27',
        'findings' => [
            $finding(['id' => 'CVE-2024-0001', 'severity' => 'critical', 'kev' => true]),
            $finding(['id' => 'CVE-2024-0002', 'package' => 'curl', 'version' => '8.0.0', 'fixedIn' => '8.0.1']),
        ],
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'scanned_at' => now(),
    ]);
});

it('opens the export menu of the findings and copies them as Markdown', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application).'/vulnerabilities';
    $page = visit($url);

    // Headless Chromium denies clipboard writes; record what the menu writes instead.
    $page->assertSee('All findings (2)')
        ->script(<<<'JS'
            () => {
                window.__copied = null;
                const record = async (value) => { window.__copied = value; };
                navigator.clipboard.writeText = record;
                navigator.clipboard.write = async (items) => record(await (await items[0].getType('text/plain')).text());
                document.querySelector('#all-findings-section button[aria-haspopup="menu"]').click();
                return true;
            }
            JS);

    $page->wait(0.3)
        ->assertSee('The 2 findings in this list, from all pages.')
        ->assertSee('Copy as Markdown table')
        ->assertSee('Download CSV')
        ->screenshot(filename: 'vulnerabilities-export-menu');

    $page->script("() => [...document.querySelectorAll('[role=menuitem]')].find(el => el.textContent.includes('Copy as Markdown table')).click()");

    $page->wait(1)
        ->assertSee('Copied 2 findings as Markdown.')
        ->assertScript("() => window.__copied.includes('| [CVE-2024-0001](https://nvd.nist.gov/vuln/detail/CVE-2024-0001) | Critical, known exploited | openssl | 3.0.1 | 3.0.2 |')")
        ->screenshot(filename: 'vulnerabilities-export-copied');
});

it('shows the export menu on the host vulnerabilities of a server', function () {
    ServerHostScan::factory()->create([
        'server_id' => $this->stack['server']->id,
        'summary' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'fixable' => 1, 'exploited' => 1, 'likely' => 0, 'total' => 1],
        'findings' => [['id' => 'CVE-2026-111497', 'severity' => 'critical', 'package' => 'libbrotli-dev', 'version' => '1.1.0-2+b7', 'fixedIn' => '1.1.0-2+deb13u1', 'kev' => true]],
    ]);
    loginAndSkipBoarding();

    $page = visit('/server/'.$this->stack['server']->uuid.'/security/vulnerabilities');
    $page->assertSee('CVE-2026-111497')
        ->script("document.querySelector('#server-host-vulnerabilities-section button[aria-haspopup=\"menu\"]').click()");

    $page->wait(0.3)
        ->assertSee('The 1 finding in this list, from all pages.')
        ->assertSee('Copy IDs')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'host-vulnerabilities-export-menu');
});

it('shows the export menu on the team Security page', function () {
    loginAndSkipBoarding();

    $page = visit('/vulnerabilities?showNotChecked=1');
    $page->assertSee('Vulns App')
        ->script("document.querySelector('.table-toolbar button[aria-haspopup=\"menu\"]').click()");

    $page->wait(0.3)
        ->assertSee('The 1 resource in this list, from all pages.')
        ->assertDontSee('Copy IDs')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'fleet-vulnerabilities-export-menu');
});
