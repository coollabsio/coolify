<?php

/**
 * Browser coverage for Livewire toggle edge cases that previously flaked:
 * - Application site type: Dynamic → Static → SPA (nginx config generation)
 * - PostgreSQL SSL enable/disable and every ssl_mode option
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-toggle-edge',
        'name' => 'Toggle Edge App',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-toggle-ssl',
        'name' => 'Toggle SSL Postgres',
        // SSL controls are only editable while status contains "exited".
        'status' => 'exited',
        'enable_ssl' => false,
        'ssl_mode' => 'prefer',
    ]);
});

// ---------------------------------------------------------------------------
// Application: static / SPA / nginx
// ---------------------------------------------------------------------------

it('shows site type control for nixpacks applications', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);

    $page->assertSee('Site type')
        ->assertSee('Dynamic')
        ->assertDontSee('Custom Nginx configuration')
        ->screenshot(filename: 'toggle-site-type-default-dynamic');
});

it('enables static site and reveals custom nginx configuration', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    selectListboxOption($page, 'siteType', 'Static', 2);

    $page->assertSee('Custom Nginx configuration')
        ->assertSee('Web server')
        ->assertSee('nginx:alpine')
        ->screenshot(filename: 'toggle-site-type-static');

    $this->application->refresh();
    expect($this->application->settings->is_static)->toBeTrue()
        ->and($this->application->settings->is_spa)->toBeFalse();

    // Reload must keep static UI state.
    visit($url)
        ->assertSee('Custom Nginx configuration')
        ->assertSee('Static')
        ->screenshot(filename: 'toggle-site-type-static-reloaded');
});

it('switches to spa and generates spa nginx try_files config', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    // Going straight to SPA (from dynamic) flips is_spa and regenerates nginx.
    selectListboxOption($page, 'siteType', 'SPA (single-page application)', 2.5);

    $page->assertSee('Custom Nginx configuration')
        ->screenshot(filename: 'toggle-site-type-spa-nginx');

    $this->application->refresh();
    expect($this->application->settings->is_static)->toBeTrue()
        ->and($this->application->settings->is_spa)->toBeTrue()
        ->and((string) $this->application->custom_nginx_configuration)
        ->toContain('try_files $uri $uri/ /index.html');
});

it('switches from spa back to static and regenerates static nginx config', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    selectListboxOption($page, 'siteType', 'SPA (single-page application)', 2.5);
    $this->application->refresh();
    expect($this->application->settings->is_spa)->toBeTrue();

    selectListboxOption($page, 'siteType', 'Static', 2.5);

    $page->assertSee('Custom Nginx configuration')
        ->screenshot(filename: 'toggle-site-type-static-nginx-from-spa');

    $this->application->refresh();
    $nginx = (string) $this->application->custom_nginx_configuration;
    expect($this->application->settings->is_static)->toBeTrue()
        ->and($this->application->settings->is_spa)->toBeFalse()
        ->and($nginx)->toContain('try_files $uri $uri.html $uri/index.html $uri/index.htm $uri/ =404')
        ->and($nginx)->not->toContain('try_files $uri $uri/ /index.html');
});
it('returns to dynamic site type and hides nginx configuration section', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit($url);
    selectListboxOption($page, 'siteType', 'Static', 2);
    $page->assertSee('Custom Nginx configuration');

    selectListboxOption($page, 'siteType', 'Dynamic', 2);

    $page->assertDontSee('Custom Nginx configuration')
        ->screenshot(filename: 'toggle-site-type-back-to-dynamic');

    $this->application->refresh();
    expect($this->application->settings->is_static)->toBeFalse()
        ->and($this->application->settings->is_spa)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Database: SSL enable + every ssl_mode
// ---------------------------------------------------------------------------

it('enables postgres ssl from the status listbox', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    $page->assertSee('SSL')
        ->assertSee('SSL mode')
        ->screenshot(filename: 'toggle-ssl-before-enable');

    selectListboxOption($page, 'enableSsl', 'Enabled', 2);

    $this->postgres->refresh();
    expect((bool) $this->postgres->enable_ssl)->toBeTrue();

    $page->assertSee('SSL mode')
        ->screenshot(filename: 'toggle-ssl-enabled');
});

it('cycles through every postgres ssl mode while ssl is enabled', function () {
    loginAndSkipBoarding();

    // Pre-enable so each mode change only hits sslMode listbox.
    $this->postgres->enable_ssl = true;
    $this->postgres->ssl_mode = 'prefer';
    $this->postgres->save();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    $page->assertSee('SSL mode');

    $modes = [
        'allow' => 'allow (insecure)',
        'prefer' => 'prefer (secure)',
        'require' => 'require (secure)',
        'verify-ca' => 'verify-ca (secure)',
        'verify-full' => 'verify-full (secure)',
    ];

    foreach ($modes as $value => $label) {
        selectListboxOption($page, 'sslMode', $label, 2);

        $this->postgres->refresh();
        expect($this->postgres->ssl_mode)->toBe($value)
            ->and((bool) $this->postgres->enable_ssl)->toBeTrue();

        // Trigger label should update to the selected mode (parens break assertSee CSS).
        $triggerText = $page->script(<<<'JS'
            (() => (document.querySelector("#sslMode-trigger")?.innerText || "").replace(/\s+/g, " ").trim())()
        JS);
        expect($triggerText)->toBe($label);

        $page->screenshot(filename: "toggle-ssl-mode-{$value}");
    }

    // Final reload confirms last mode sticks.
    $reloaded = visit($url);
    $triggerText = $reloaded->script(<<<'JS'
        (() => (document.querySelector("#sslMode-trigger")?.innerText || "").replace(/\s+/g, " ").trim())()
    JS);
    expect($triggerText)->toBe('verify-full (secure)');
    $reloaded->screenshot(filename: 'toggle-ssl-mode-verify-full-reloaded');

    $this->postgres->refresh();
    expect($this->postgres->ssl_mode)->toBe('verify-full');
});
it('disables postgres ssl after modes have been set', function () {
    loginAndSkipBoarding();

    $this->postgres->enable_ssl = true;
    $this->postgres->ssl_mode = 'require';
    $this->postgres->save();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);
    selectListboxOption($page, 'enableSsl', 'Disabled', 2);

    $this->postgres->refresh();
    expect((bool) $this->postgres->enable_ssl)->toBeFalse()
        // Mode is retained for when SSL is re-enabled.
        ->and($this->postgres->ssl_mode)->toBe('require');

    $page->screenshot(filename: 'toggle-ssl-disabled');
});

it('keeps ssl mode selector disabled while database is running', function () {
    loginAndSkipBoarding();

    $this->postgres->status = 'running:healthy';
    $this->postgres->enable_ssl = false;
    $this->postgres->save();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    );

    $page = visit($url);

    // Triggers should be disabled when not exited.
    $enableDisabled = $page->script('(() => document.querySelector("#enableSsl-trigger")?.disabled === true)()');
    $modeDisabled = $page->script('(() => document.querySelector("#sslMode-trigger")?.disabled === true)()');

    expect($enableDisabled)->toBeTrue()
        ->and($modeDisabled)->toBeTrue();

    $page->screenshot(filename: 'toggle-ssl-disabled-while-running');
});
