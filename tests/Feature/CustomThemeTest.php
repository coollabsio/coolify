<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    User::factory()->create();
    config(['constants.coolify.self_hosted' => true]);
    File::delete(customThemePath());
});

afterEach(fn () => File::delete(customThemePath()));

function writeCustomTheme(string $css): void
{
    File::ensureDirectoryExists(dirname(customThemePath()));
    File::put(customThemePath(), $css);
}

test('stylesheet route returns 404 when the theme file is missing', function () {
    $this->get('/custom-theme.css')->assertNotFound();
});

test('stylesheet route serves the theme file as css', function () {
    writeCustomTheme('html[data-theme="custom"] { --color-accent: #123456; }');

    $this->get('/custom-theme.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('--color-accent: #123456', escape: false);
});

test('layout links the custom stylesheet after the application css', function () {
    writeCustomTheme('html[data-theme="custom"] { --color-accent: #123456; }');

    $this->get('/login')->assertOk()->assertSee('/custom-theme.css?v=');

    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    expect(strpos($layout, "route('custom-theme.css'"))->toBeGreaterThan(strpos($layout, "@vite(['resources/js/app.js', 'resources/css/app.css'])"));
});

test('layout omits the custom stylesheet when the theme file is missing', function () {
    $this->get('/login')->assertOk()->assertDontSee('/custom-theme.css');
});

test('stylesheet version changes with the file content', function () {
    $version = fn () => str($this->get('/login')->getContent())->after('/custom-theme.css?v=')->before('"')->value();

    writeCustomTheme('html { --a: 1; }');
    $first = $version();

    writeCustomTheme('html { --a: 2; }');

    expect($first)->not->toBe('')->and($version())->not->toBe($first);
});

test('cloud does not expose the custom stylesheet', function () {
    writeCustomTheme('html { --a: 1; }');
    config(['constants.coolify.self_hosted' => false]);

    $this->get('/custom-theme.css')->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee('/custom-theme.css');
});
