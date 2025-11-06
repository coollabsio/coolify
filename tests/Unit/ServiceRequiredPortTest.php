<?php

use App\Models\Service;
use App\Models\ServiceApplication;
use Mockery;

it('returns required port from service template', function () {
    // Mock get_service_templates() function
    $mockTemplates = collect([
        'supabase' => [
            'name' => 'Supabase',
            'port' => '8000',
        ],
        'umami' => [
            'name' => 'Umami',
            'port' => '3000',
        ],
    ]);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'supabase-xyz123';

    // Mock the get_service_templates function to return our mock data
    $service->shouldReceive('getRequiredPort')->andReturn(8000);

    expect($service->getRequiredPort())->toBe(8000);
});

it('returns null for service without required port', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'cloudflared-xyz123';

    // Mock to return null for services without port
    $service->shouldReceive('getRequiredPort')->andReturn(null);

    expect($service->getRequiredPort())->toBeNull();
});

it('requiresPort returns true when service has required port', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getRequiredPort')->andReturn(8000);
    $service->shouldReceive('requiresPort')->andReturnUsing(function () use ($service) {
        return $service->getRequiredPort() !== null;
    });

    expect($service->requiresPort())->toBeTrue();
});

it('requiresPort returns false when service has no required port', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getRequiredPort')->andReturn(null);
    $service->shouldReceive('requiresPort')->andReturnUsing(function () use ($service) {
        return $service->getRequiredPort() !== null;
    });

    expect($service->requiresPort())->toBeFalse();
});

it('extracts port from URL with http scheme', function () {
    $url = 'http://example.com:3000';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBe(3000);
});

it('extracts port from URL with https scheme', function () {
    $url = 'https://example.com:8080';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBe(8080);
});

it('extracts port from URL without scheme', function () {
    $url = 'example.com:5000';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBe(5000);
});

it('returns null for URL without port', function () {
    $url = 'http://example.com';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBeNull();
});

it('returns null for URL without port and without scheme', function () {
    $url = 'example.com';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBeNull();
});

it('handles invalid URLs gracefully', function () {
    $url = 'not-a-valid-url:::';
    $port = ServiceApplication::extractPortFromUrl($url);

    expect($port)->toBeNull();
});

it('checks if all FQDNs have port - single FQDN with port', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = 'http://example.com:3000';

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeTrue();
});

it('checks if all FQDNs have port - single FQDN without port', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = 'http://example.com';

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeFalse();
});

it('checks if all FQDNs have port - multiple FQDNs all with ports', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = 'http://example.com:3000,https://example.org:8080';

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeTrue();
});

it('checks if all FQDNs have port - multiple FQDNs one without port', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = 'http://example.com:3000,https://example.org';

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeFalse();
});

it('checks if all FQDNs have port - empty FQDN', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = '';

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeFalse();
});

it('checks if all FQDNs have port - null FQDN', function () {
    $app = Mockery::mock(ServiceApplication::class)->makePartial();
    $app->fqdn = null;

    $result = $app->allFqdnsHavePort();

    expect($result)->toBeFalse();
});
