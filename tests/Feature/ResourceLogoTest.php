<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Service;
use App\Models\StandaloneKeydb;
use App\Models\StandalonePostgresql;

beforeEach(function () {
    config(['cache.default' => 'array']);
});

test('databases get the logo of their engine', function () {
    expect(resource_logo(new StandalonePostgresql))
        ->toBe(['src' => resource_logo_url('postgres'), 'dark' => null, 'fallback' => null, 'padded' => false, 'favicon' => null])
        ->and(resource_logo(new StandaloneKeydb))
        ->toBe(['src' => resource_logo_url('keydb'), 'dark' => resource_logo_url('keydb-dark'), 'fallback' => null, 'padded' => false, 'favicon' => null]);
});

test('applications get the logo of how they are built', function (string $buildPack, ?string $sourceType, string $logo) {
    $application = (new Application)->forceFill(['build_pack' => $buildPack, 'source_type' => $sourceType]);

    expect(resource_logo($application)['src'])->toBe(resource_logo_url($logo));
})->with([
    ['nixpacks', null, 'public-repo'],
    ['railpack', GithubApp::class, 'github-app'],
    ['static', GitlabApp::class, 'gitlab-app'],
    ['dockerimage', null, 'docker-image'],
    ['dockerfile', GithubApp::class, 'dockerfile'],
    ['dockercompose', GitlabApp::class, 'docker-compose'],
]);

test('services get the logo of their template, with the CDN as fallback', function () {
    $template = get_service_templates()->get('n8n');

    expect(resource_logo(new Service(['name' => 'renamed', 'service_type' => 'n8n'])))->toBe([
        'src' => asset($template->logo),
        'dark' => null,
        'fallback' => service_logo_urls($template->logo)['logo_cdn_url'],
        'padded' => true,
        'favicon' => null,
    ]);
});

test('services created before the service type was stored use the template in their name', function () {
    expect(resource_logo(new Service(['name' => 'n8n-abc123']))['src'])->toBe(asset(get_service_templates()->get('n8n')->logo));
});

test('services without a template logo have no logo', function () {
    expect(resource_logo(new Service(['name' => 'custom-compose'])))->toBeNull();
});

test('applications with a domain get the favicon of their first domain', function (?string $fqdn, ?string $favicon) {
    $application = (new Application)->forceFill(['build_pack' => 'nixpacks', 'fqdn' => $fqdn]);

    expect(resource_logo($application)['favicon'])->toBe($favicon);
})->with([
    ['https://app.example.com/dashboard,https://www.example.com', 'https://app.example.com/favicon.ico'],
    [' http://app.example.com:8080 ', 'http://app.example.com:8080/favicon.ico'],
    [null, null],
    ['app.example.com', null],
    ['https://*.example.com', null],
]);

test('applications behind HTTP basic auth get no favicon, so the browser does not ask for credentials', function () {
    $application = (new Application)->forceFill([
        'build_pack' => 'nixpacks',
        'fqdn' => 'https://app.example.com',
        'is_http_basic_auth_enabled' => true,
    ]);

    expect(resource_logo($application)['favicon'])->toBeNull();
});

test('resource logo URLs carry the file modification time, so browsers load changed logos', function () {
    $modifiedAt = filemtime(public_path('svgs/resources/docker-image.svg'));

    expect(resource_logo_url('docker-image'))->toBe(asset('svgs/resources/docker-image.svg')."?v={$modifiedAt}")
        ->and(resource_logo_url('missing-logo'))->toBe(asset('svgs/resources/missing-logo.svg'));
});
