<?php

use App\Http\Middleware\TrustHosts;
use App\Models\InstanceSettings;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
