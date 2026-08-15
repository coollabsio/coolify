<?php

use App\Services\Flux\AgentTokenIssuer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->keyDir = storage_path('framework/testing/flux-keygen-'.bin2hex(random_bytes(4)));
    $this->privateKeyPath = $this->keyDir.'/jwt.priv';
    $this->publicKeyPath = $this->keyDir.'/jwt.pub';

    Config::set('flux.jwt_private_key_path', $this->privateKeyPath);
    Config::set('flux.jwt_public_key_path', $this->publicKeyPath);
});

afterEach(function () {
    File::deleteDirectory($this->keyDir);
});

/**
 * @return string octal file/dir mode, e.g. "0600"
 */
function fluxKeyMode(string $path): string
{
    clearstatcache();

    return sprintf('%04o', fileperms($path) & 0777);
}

it('generates a working ES256 keypair at the configured paths with strict perms', function () {
    $this->artisan('v5:flux-generate-keys')->assertSuccessful();

    expect(File::exists($this->privateKeyPath))->toBeTrue()
        ->and(File::exists($this->publicKeyPath))->toBeTrue()
        ->and(fluxKeyMode($this->privateKeyPath))->toBe('0600')
        ->and(fluxKeyMode($this->publicKeyPath))->toBe('0644')
        ->and(fluxKeyMode($this->keyDir))->toBe('0700');

    // The generated private key must be usable by the real issuer...
    $token = app(AgentTokenIssuer::class)->issue('100.64.0.10');

    expect(substr_count($token, '.'))->toBe(2);

    // ...and verifiable with the generated public key (proves the pair matches).
    $claims = JWT::decode($token, new Key(File::get($this->publicKeyPath), 'ES256'));

    expect($claims->sub)->toBe('100.64.0.10');
});

it('refuses to overwrite an existing private key without --force', function () {
    File::ensureDirectoryExists($this->keyDir);
    File::put($this->privateKeyPath, 'SENTINEL-PRIVATE-KEY');

    $this->artisan('v5:flux-generate-keys')
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get($this->privateKeyPath))->toBe('SENTINEL-PRIVATE-KEY');
});

it('overwrites an existing private key with --force and produces a valid keypair', function () {
    File::ensureDirectoryExists($this->keyDir);
    File::put($this->privateKeyPath, 'SENTINEL-PRIVATE-KEY');

    $this->artisan('v5:flux-generate-keys --force')->assertSuccessful();

    expect(File::get($this->privateKeyPath))->not->toBe('SENTINEL-PRIVATE-KEY')
        ->and(fluxKeyMode($this->privateKeyPath))->toBe('0600');

    $token = app(AgentTokenIssuer::class)->issue('100.64.0.10');

    expect(substr_count($token, '.'))->toBe(2);
});

it('prints the public key when --show-public is passed', function () {
    $this->artisan('v5:flux-generate-keys --show-public')
        ->expectsOutputToContain('BEGIN PUBLIC KEY')
        ->assertSuccessful();
});
