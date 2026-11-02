<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\PasskeyRelyingParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->withoutVite();
});

function setPasskeyInstance(?string $fqdn, string $appUrl, bool $enabled = true): void
{
    InstanceSettings::query()->where('id', 0)->update(['fqdn' => $fqdn, 'is_passkey_enabled' => $enabled]);
    config()->set('app.url', $appUrl);
    Once::flush();
}

it('resolves the relying party', function (?string $fqdn, string $appUrl, string $requestUrl, ?array $expected) {
    setPasskeyInstance($fqdn, $appUrl);

    expect(PasskeyRelyingParty::resolve(Request::create($requestUrl)))->toBe($expected);
})->with([
    'https fqdn' => ['https://coolify.example.com', 'http://localhost', 'https://coolify.example.com/login',
        ['id' => 'coolify.example.com', 'origins' => ['https://coolify.example.com']]],
    'fqdn behind http proxy' => ['https://coolify.example.com', 'http://localhost', 'http://coolify.example.com/login',
        ['id' => 'coolify.example.com', 'origins' => ['https://coolify.example.com']]],
    'app url fallback keeps the port' => [null, 'https://dev.example.ts.net:8443', 'https://dev.example.ts.net:8443/login',
        ['id' => 'dev.example.ts.net', 'origins' => ['https://dev.example.ts.net:8443']]],
    'localhost uses the request origin' => [null, 'http://localhost', 'http://localhost:8000/login',
        ['id' => 'localhost', 'origins' => ['http://localhost:8000']]],
    'fqdn on a custom port' => ['https://coolify.example.com', 'http://localhost', 'https://coolify.example.com:8443/login',
        ['id' => 'coolify.example.com', 'origins' => ['https://coolify.example.com', 'https://coolify.example.com:8443']]],
    'fqdn with uppercase letters' => ['https://Coolify.Example.com', 'http://localhost', 'https://coolify.example.com/login',
        ['id' => 'coolify.example.com', 'origins' => ['https://coolify.example.com']]],
    'http fqdn' => ['http://coolify.example.com', 'http://localhost', 'http://coolify.example.com/login', null],
    'raw ip' => [null, 'https://203.0.113.10', 'https://203.0.113.10/login', null],
]);

it('is not available on a host that is not the instance host', function () {
    setPasskeyInstance('https://coolify.example.com', 'http://localhost');

    expect(PasskeyRelyingParty::isAvailable(Request::create('http://203.0.113.10:8000/login')))->toBeFalse();
});

it('is not available when the instance toggle is off', function () {
    setPasskeyInstance(null, 'http://localhost', enabled: false);

    expect(PasskeyRelyingParty::isAvailable(Request::create('http://localhost/login')))->toBeFalse();
});

it('returns login options with the resolved relying party', function () {
    setPasskeyInstance('https://coolify.example.com', 'http://localhost');

    $this->getJson('https://coolify.example.com/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('options.rpId', 'coolify.example.com');
});

it('returns 404 on passkey routes when passkeys are not available', function (bool $enabled, string $url) {
    setPasskeyInstance(null, 'http://localhost', $enabled);

    $this->getJson($url)->assertNotFound();
})->with([
    'toggle off' => [false, 'http://localhost/passkeys/login/options'],
    'raw ip host' => [true, 'http://203.0.113.10:8000/passkeys/login/options'],
]);

it('keeps the password login page reachable when passkeys are not available', function () {
    setPasskeyInstance(null, 'http://localhost', enabled: false);
    User::factory()->create();

    $this->get('http://203.0.113.10:8000/login')->assertOk();
});

it('rate limits the passkey routes by client ip', function () {
    setPasskeyInstance(null, 'http://localhost');

    foreach (range(1, 30) as $attempt) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }

    $this->getJson('/passkeys/login/options')->assertStatus(429);
});

it('does not need the instance settings on other fortify routes', function () {
    InstanceSettings::query()->delete();
    Once::flush();

    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');
});
