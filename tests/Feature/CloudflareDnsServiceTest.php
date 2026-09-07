<?php

use App\Models\Server;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function cloudflareQueryValue(Request $request, string $key): ?string
{
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

    return $query[$key] ?? null;
}

test('it creates missing Cloudflare DNS records', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $zoneName = cloudflareQueryValue($request, 'name');

        if (str_contains($request->url(), '/zones?') && $zoneName === 'app.example.com') {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (str_contains($request->url(), '/zones?') && $zoneName === 'example.com') {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-id', 'name' => 'example.com']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'record-id']], 200);
        }

        return Http::response(['success' => false], 500);
    });

    $server = new Server(['name' => 'Production', 'ip' => '192.0.2.10']);
    $service = new CloudflareDnsService('cloudflare-token');

    $results = $service->ensureRecordsForDomains($server, ['https://app.example.com'], proxied: false);

    expect($results)->toHaveCount(1)
        ->and($results[0]['action'])->toBe('created')
        ->and($results[0]['type'])->toBe('A')
        ->and($results[0]['content'])->toBe('192.0.2.10');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/zones/zone-id/dns_records')
        && $request['name'] === 'app.example.com'
        && $request['content'] === '192.0.2.10'
        && $request['proxied'] === false);
});

test('it updates existing Cloudflare DNS records with wrong content or proxy state', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $zoneName = cloudflareQueryValue($request, 'name');

        if (str_contains($request->url(), '/zones?') && $zoneName === 'example.com') {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-id', 'name' => 'example.com']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => [['id' => 'record-id', 'content' => '198.51.100.1', 'proxied' => false]],
            ], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), '/zones/zone-id/dns_records/record-id')) {
            return Http::response(['success' => true, 'result' => ['id' => 'record-id']], 200);
        }

        return Http::response(['success' => true, 'result' => []], 200);
    });

    $server = new Server(['name' => 'Production', 'ip' => '192.0.2.10']);
    $service = new CloudflareDnsService('cloudflare-token');

    $results = $service->ensureRecordsForDomains($server, ['https://example.com'], proxied: true);

    expect($results[0]['action'])->toBe('updated');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/zones/zone-id/dns_records/record-id')
        && $request['content'] === '192.0.2.10'
        && $request['proxied'] === true);
});

test('it leaves matching Cloudflare DNS records unchanged', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/zones?')) {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-id', 'name' => 'example.com']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => [['id' => 'record-id', 'content' => '192.0.2.10', 'proxied' => false]],
            ], 200);
        }

        return Http::response(['success' => false], 500);
    });

    $server = new Server(['name' => 'Production', 'ip' => '192.0.2.10']);
    $service = new CloudflareDnsService('cloudflare-token');

    $results = $service->ensureRecordsForDomains($server, ['https://example.com'], proxied: false);

    expect($results[0]['action'])->toBe('unchanged');

    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PATCH'], true));
});
