<?php

use App\Livewire\Source\Github\Change;

function githubChangeWithEndpoints(?string $fqdn = null, ?string $ipv4 = null, ?string $ipv6 = null): Change
{
    $component = new Change;
    $component->fqdn = $fqdn;
    $component->ipv4 = $ipv4;
    $component->ipv6 = $ipv6;

    return $component;
}

it('has no public webhook endpoint on a default install without domain or public IP', function (string $appUrl) {
    config()->set('app.url', $appUrl);

    expect(githubChangeWithEndpoints()->hasPublicWebhookEndpoint())->toBeFalse();
})->with([
    'http://localhost',
    'http://localhost:8000',
    'http://coolify.localhost',
    'http://127.0.0.1:8000',
    'http://192.168.1.10:8000',
    'http://[::1]:8000',
    '',
]);

it('has a public webhook endpoint when the instance has a domain or a public IP', function (array $endpoints) {
    config()->set('app.url', 'http://localhost');

    expect(githubChangeWithEndpoints(...$endpoints)->hasPublicWebhookEndpoint())->toBeTrue();
})->with([
    'domain' => [['fqdn' => 'https://coolify.example.com']],
    'IPv4' => [['ipv4' => 'http://203.0.113.10:8000']],
    'IPv6' => [['ipv6' => 'http://[2001:db8::1]:8000']],
]);

it('has a public webhook endpoint when APP_URL is a public host', function (string $appUrl) {
    config()->set('app.url', $appUrl);

    expect(githubChangeWithEndpoints()->hasPublicWebhookEndpoint())->toBeTrue();
})->with([
    'https://coolify.example.com',
    'http://8.8.8.8:8000',
]);
