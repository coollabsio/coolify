<?php

use App\Models\GithubApp;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
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

function commandPaletteSettingsPageUrl(string $page, array $stack): string
{
    return match ($page) {
        'instance settings' => '/settings/oauth',
        'team' => '/team',
        'keys and tokens' => '/security/private-key',
        'notifications' => '/notifications/email',
        'shared variables' => '/shared-variables',
        'destination' => '/destination/'.$stack['destination']->uuid,
        's3 storage' => '/storages/'.S3Storage::create([
            'team_id' => 0, 'name' => 'Palette S3', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret',
            'bucket' => 'bucket', 'endpoint' => 'https://s3.example.com',
        ])->uuid,
        'github app' => '/source/github/'.GithubApp::create([
            'name' => 'palette-app', 'organization' => 'acme', 'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com', 'custom_user' => 'git', 'custom_port' => 22, 'app_id' => 1234,
            'installation_id' => 5678, 'webhook_secret' => 'secret', 'private_key_id' => $stack['privateKey']->id,
            'team_id' => 0, 'is_system_wide' => false,
        ])->uuid,
        'database backup' => (function () use ($stack): string {
            $database = createBrowserPostgresql($stack, ['uuid' => 'db-palette-backup']);
            $backup = ScheduledDatabaseBackup::create([
                'team_id' => 0, 'frequency' => '0 0 * * *', 'database_type' => $database->getMorphClass(),
                'database_id' => $database->id,
            ]);

            return "/project/{$stack['project']->uuid}/environment/{$stack['environment']->uuid}/database/{$database->uuid}/backups/{$backup->uuid}";
        })(),
    };
}

it('searches the sidebar pages of other settings pages', function (string $pageType, string $expectedLabel) {
    $url = commandPaletteSettingsPageUrl($pageType, $this->stack);

    loginAndSkipBoarding();

    $page = visit($url);
    $page->assertVisible('button:visible:has-text("Search page & global")');
    $page->script("window.dispatchEvent(new Event('open-global-search'))");

    $page->assertVisible('.command-palette-scope')
        ->keys('.command-palette-input', 'Tab')
        ->assertSeeIn('.command-palette-body', $expectedLabel)
        ->screenshot(filename: 'command-palette-page-'.str($pageType)->slug());
})->with([
    ['instance settings', 'Updates'],
    ['team', 'Members'],
    ['keys and tokens', 'API Tokens'],
    ['notifications', 'Telegram'],
    ['shared variables', 'Environments'],
    ['destination', 'Danger Zone'],
    ['s3 storage', 'Resources'],
    ['github app', 'Permissions'],
    ['database backup', 'Retention'],
]);

it('searches the sections of a page without a settings sidebar and scrolls to them', function () {
    loginAndSkipBoarding();

    $page = visit('/profile');
    $page->assertVisible('button:visible:has-text("Search page & global")');
    $page->script("window.dispatchEvent(new Event('open-global-search'))");

    $page->assertVisible('.command-palette-scope')
        ->keys('.command-palette-input', 'Tab')
        ->type('.command-palette-input', 'two-factor')
        ->assertSeeIn('.command-palette-body', 'Two-factor authentication')
        ->click('.command-palette-body a.search-result-item')
        ->assertMissing('.command-palette-input')
        ->wait(1)
        ->assertScript(<<<'JS'
            () => {
                const heading = [...document.querySelectorAll('.application-settings-section h2')]
                    .find(el => el.textContent.includes('Two-factor authentication'));
                const top = heading.getBoundingClientRect().top;

                return top >= 0 && top < window.innerHeight;
            }
            JS)
        ->screenshot(filename: 'command-palette-profile-section');
});

it('lists a section once when the sidebar already links to it', function () {
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application));
    $page->assertSee('Palette App');

    expect($page->script("window.currentPageSearchItems().filter(item => item.label === 'Container labels').length"))->toBe(1);
});
