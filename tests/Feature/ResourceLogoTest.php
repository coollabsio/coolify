<?php

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneKeydb;
use App\Models\StandalonePostgresql;

beforeEach(function () {
    config(['cache.default' => 'array']);
});

test('databases get the logo of their engine', function () {
    expect(resource_logo(new StandalonePostgresql))
        ->toBe(['src' => asset('svgs/resources/postgres.svg'), 'dark' => null, 'fallback' => null, 'padded' => false])
        ->and(resource_logo(new StandaloneKeydb))
        ->toBe(['src' => asset('svgs/resources/keydb.svg'), 'dark' => asset('svgs/resources/keydb-dark.svg'), 'fallback' => null, 'padded' => false]);
});

test('applications get the logo of how they are built', function (string $buildPack, string $logo) {
    expect(resource_logo(new Application(['build_pack' => $buildPack]))['src'])->toBe(asset("svgs/resources/{$logo}.svg"));
})->with([
    ['nixpacks', 'public-repo'],
    ['dockerimage', 'docker-image'],
    ['dockerfile', 'dockerfile'],
    ['dockercompose', 'docker-compose'],
]);

test('services get the logo of their template, with the CDN as fallback', function () {
    $template = get_service_templates()->get('n8n');

    expect(resource_logo(new Service(['name' => 'renamed', 'service_type' => 'n8n'])))->toBe([
        'src' => asset($template->logo),
        'dark' => null,
        'fallback' => service_logo_urls($template->logo)['logo_cdn_url'],
        'padded' => true,
    ]);
});

test('services created before the service type was stored use the template in their name', function () {
    expect(resource_logo(new Service(['name' => 'n8n-abc123']))['src'])->toBe(asset(get_service_templates()->get('n8n')->logo));
});

test('services without a template logo have no logo', function () {
    expect(resource_logo(new Service(['name' => 'custom-compose'])))->toBeNull();
});
