<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-palette-settings',
        'name' => 'Palette App',
    ]);
});

it('shows the settings of the open resource in the command palette and switches scope with Tab', function () {
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application));
    $page->assertSee('Palette App')
        ->assertMissing('[aria-label="Filter settings"]')
        ->assertVisible('button:visible:has-text("Search page & global")');

    $page->script("window.dispatchEvent(new Event('open-global-search'))");

    $page->assertVisible('.command-palette-input')
        ->assertAttribute('.command-palette-scope-option:has-text("Global")', 'aria-pressed', 'true')
        ->assertAttribute('.command-palette-scope-option:has-text("This page")', 'aria-pressed', 'false')
        ->type('.command-palette-input', 'runtime')
        ->assertSeeIn('.command-palette-body', 'This page')
        ->assertSeeIn('.command-palette-body', 'Runtime Logs')
        ->assertSeeIn('.command-palette-body', 'Settings · General')
        ->keys('.command-palette-input', 'Tab')
        ->assertAttribute('.command-palette-scope-option:has-text("This page")', 'aria-pressed', 'true')
        ->assertAttribute('.command-palette-scope-option:has-text("Global")', 'aria-pressed', 'false')
        ->assertScript("document.activeElement === document.querySelector('.command-palette-input')")
        ->type('.command-palette-input', 'zzz-nothing')
        ->assertSeeIn('.command-palette-body', 'Nothing on this page')
        ->type('.command-palette-input', 'runtime logs')
        ->screenshot(filename: 'command-palette-this-page-scope')
        ->click('.command-palette-body a.search-result-item')
        ->assertPathEndsWith('/logs')
        ->screenshot(filename: 'command-palette-opened-runtime-logs');
});

it('does not show the scope switch on pages without a settings sidebar', function () {
    loginAndSkipBoarding();

    $page = visit('/dashboard');
    $page->assertDontSee('page & global');
    $page->script("window.dispatchEvent(new Event('open-global-search'))");

    $page->assertVisible('.command-palette-input')
        ->assertMissing('.command-palette-scope')
        ->screenshot(filename: 'command-palette-without-scope');
});

it('focuses the search input when the palette is opened from the mobile menu', function () {
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application))
        ->on()->mobile();

    $page->assertSee('Palette App')
        ->click('button:visible:has-text("Open sidebar")')
        ->assertSee('Search page & global')
        ->screenshot(filename: 'command-palette-mobile-menu')
        ->click('button:visible:has-text("Search")')
        ->assertVisible('.command-palette-input')
        ->wait(0.3)
        ->assertScript("document.activeElement === document.querySelector('.command-palette-input')")
        ->assertVisible('.command-palette-scope')
        ->screenshot(filename: 'command-palette-mobile');
});
