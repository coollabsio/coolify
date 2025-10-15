<?php

use App\Http\Middleware\TrustHosts;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Clear cache before each test to ensure isolation
    Cache::forget('instance_settings_fqdn_host');
});

it('trusts the configured FQDN from InstanceSettings', function () {
    // Create instance settings with FQDN
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    expect($hosts)->toContain('coolify.example.com');
});

it('rejects password reset request with malicious host header', function () {
    // Set up instance settings with legitimate FQDN
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    // The malicious host should NOT be in the trusted hosts
    expect($hosts)->not->toContain('coolify.example.com.evil.com');
    expect($hosts)->toContain('coolify.example.com');
});

it('handles missing FQDN gracefully', function () {
    // Create instance settings without FQDN
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null]
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    // Should still return APP_URL pattern without throwing
    expect($hosts)->not->toBeEmpty();
});

it('filters out null and empty values from trusted hosts', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => '']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    // Should not contain empty strings or null
    foreach ($hosts as $host) {
        if ($host !== null) {
            expect($host)->not->toBeEmpty();
        }
    }
});

it('extracts host from FQDN with protocol and port', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com:8443']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    expect($hosts)->toContain('coolify.example.com');
});

it('handles exception during InstanceSettings fetch', function () {
    // Drop the instance_settings table to simulate installation
    \Schema::dropIfExists('instance_settings');

    $middleware = new TrustHosts($this->app);

    // Should not throw an exception
    $hosts = $middleware->hosts();

    expect($hosts)->not->toBeEmpty();
});

it('trusts IP addresses with port', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'http://65.21.3.91:8000']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    expect($hosts)->toContain('65.21.3.91');
});

it('trusts IP addresses without port', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'http://192.168.1.100']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    expect($hosts)->toContain('192.168.1.100');
});

it('rejects malicious host when using IP address', function () {
    // Simulate an instance using IP address
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'http://65.21.3.91:8000']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    // The malicious host attempting to mimic the IP should NOT be trusted
    expect($hosts)->not->toContain('65.21.3.91.evil.com');
    expect($hosts)->not->toContain('evil.com');
    expect($hosts)->toContain('65.21.3.91');
});

it('trusts IPv6 addresses', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'http://[2001:db8::1]:8000']
    );

    $middleware = new TrustHosts($this->app);
    $hosts = $middleware->hosts();

    // IPv6 addresses are enclosed in brackets, getHost() should handle this
    expect($hosts)->toContain('[2001:db8::1]');
});

it('invalidates cache when FQDN is updated', function () {
    // Set initial FQDN
    $settings = InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://old-domain.com']
    );

    // First call should cache it
    $middleware = new TrustHosts($this->app);
    $hosts1 = $middleware->hosts();
    expect($hosts1)->toContain('old-domain.com');

    // Verify cache exists
    expect(Cache::has('instance_settings_fqdn_host'))->toBeTrue();

    // Update FQDN - should trigger cache invalidation
    $settings->fqdn = 'https://new-domain.com';
    $settings->save();

    // Cache should be cleared
    expect(Cache::has('instance_settings_fqdn_host'))->toBeFalse();

    // New call should return updated host
    $middleware2 = new TrustHosts($this->app);
    $hosts2 = $middleware2->hosts();
    expect($hosts2)->toContain('new-domain.com');
    expect($hosts2)->not->toContain('old-domain.com');
});

it('caches trusted hosts to avoid database queries on every request', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com']
    );

    // Clear cache first
    Cache::forget('instance_settings_fqdn_host');

    // First call - should query database and cache result
    $middleware1 = new TrustHosts($this->app);
    $hosts1 = $middleware1->hosts();

    // Verify result is cached
    expect(Cache::has('instance_settings_fqdn_host'))->toBeTrue();
    expect(Cache::get('instance_settings_fqdn_host'))->toBe('coolify.example.com');

    // Subsequent calls should use cache (no DB query)
    $middleware2 = new TrustHosts($this->app);
    $hosts2 = $middleware2->hosts();

    expect($hosts1)->toBe($hosts2);
    expect($hosts2)->toContain('coolify.example.com');
});

it('caches negative results when no FQDN is configured', function () {
    // Create instance settings without FQDN
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null]
    );

    // Clear cache first
    Cache::forget('instance_settings_fqdn_host');

    // First call - should query database and cache empty string sentinel
    $middleware1 = new TrustHosts($this->app);
    $hosts1 = $middleware1->hosts();

    // Verify empty string sentinel is cached (not null, which wouldn't be cached)
    expect(Cache::has('instance_settings_fqdn_host'))->toBeTrue();
    expect(Cache::get('instance_settings_fqdn_host'))->toBe('');

    // Subsequent calls should use cached sentinel value
    $middleware2 = new TrustHosts($this->app);
    $hosts2 = $middleware2->hosts();

    expect($hosts1)->toBe($hosts2);
    // Should only contain APP_URL pattern, not any FQDN
    expect($hosts2)->not->toBeEmpty();
});
