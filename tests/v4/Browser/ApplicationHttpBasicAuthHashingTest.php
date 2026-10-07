<?php

use App\Enums\HttpBasicAuthHashAlgorithm;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Server::flushIdentityMap());

afterEach(fn () => Server::flushIdentityMap());

it('replaces the cost factor with memory and iterations when Argon2id is selected', function () {
    $stack = seedBrowserResourceStack();
    $stack['server']->forceFill(['proxy' => [
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: 'lucaslorentz/caddy-docker-proxy:2.13-alpine'\n",
    ]])->save();
    $application = createBrowserApplication($stack, [
        'name' => 'Basic Auth App',
        'fqdn' => 'https://basic-auth.example.com',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'admin',
        'http_basic_auth_password' => 'secret',
    ]);
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($stack['project'], $stack['environment'], $application));
    $page->assertSee('Cost factor')
        ->script("document.querySelector('#security-section').scrollIntoView()");

    selectListboxOption($page, 'httpBasicAuthHashAlgorithm', 'Argon2id Memory-hard, more resistant to cracking');

    $page->assertSeeIn('#httpBasicAuthArgon2idMemoryCost-trigger', '64 MiB')
        ->assertSeeIn('#httpBasicAuthArgon2idTimeCost-trigger', '4')
        ->assertDontSee('Cost factor')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-basic-auth-argon2id');

    expect($application->fresh()->http_basic_auth_hash_algorithm)->toBe(HttpBasicAuthHashAlgorithm::ARGON2ID);
});
