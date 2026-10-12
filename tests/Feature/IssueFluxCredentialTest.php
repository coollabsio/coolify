<?php

use App\Actions\Sentinel\IssueFluxCredential;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('F', 32));
    config()->set('constants.flux.signing_key_id', 'test-key');
    config()->set('constants.flux.signing_private_key', base64_encode(sodium_crypto_sign_secretkey($keyPair)));
    config()->set('constants.flux.signing_public_key', base64_encode(sodium_crypto_sign_publickey($keyPair)));
    config()->set('constants.flux.issuer', 'coolify-test');
    $this->node = Node::factory()->create(['team_id' => Team::factory()->create()->id]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    JWT::$timestamp = null;
});

function decodeFluxCredential(string $credential): array
{
    return (array) JWT::decode($credential, new Key(config('constants.flux.signing_public_key'), 'EdDSA'));
}

it('binds the credential to the Node and the Flux audience with a 15 minute lifetime', function () {
    CarbonImmutable::setTestNow('2026-10-11 12:00:00');
    JWT::$timestamp = CarbonImmutable::now()->timestamp;

    $issued = (new IssueFluxCredential)->issue($this->node, ['system.ping.v1'], 1, 1);
    $claims = decodeFluxCredential($issued['credential']);
    [$header] = explode('.', $issued['credential']);

    expect($claims)->toMatchArray([
        'iss' => 'coolify-test',
        'aud' => 'flux',
        'purpose' => 'node-control-channel',
        'sub' => $this->node->uuid,
    ])
        ->and($claims['exp'] - $claims['iat'])->toBe(900)
        ->and($claims['nbf'])->toBeLessThanOrEqual($claims['iat'])
        ->and($issued['expires_at']->timestamp)->toBe($claims['exp'])
        ->and(json_decode(JWT::urlsafeB64Decode($header), true)['kid'])->toBe('test-key');
});

it('grants only allow-listed capabilities and drops anything else the Sentinel claims', function () {
    $issued = (new IssueFluxCredential)->issue($this->node, [
        'system.ping.v1',
        'admin.shell.v1',
        'workload.lifecycle.v1',
        'discovery.corrosion.endpoints.reconcile.v1',
        'logs.follow.v1',
    ], 1, 1);

    expect($issued['capabilities'])->toBe(['system.ping.v1', 'workload.lifecycle.v1'])
        ->and(decodeFluxCredential($issued['credential'])['caps'])->toBe(['system.ping.v1', 'workload.lifecycle.v1']);
});

it('clamps the negotiated protocol range to version 1', function () {
    $claims = decodeFluxCredential((new IssueFluxCredential)->issue($this->node, [], 0, 7)['credential']);

    expect($claims['pmin'])->toBe(1)->and($claims['pmax'])->toBe(1)->and($claims['caps'])->toBe([]);
});

it('produces a credential that a different key cannot verify', function () {
    $credential = (new IssueFluxCredential)->issue($this->node, ['system.ping.v1'], 1, 1)['credential'];
    $otherKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat('X', 32))));

    expect(fn () => JWT::decode($credential, new Key($otherKey, 'EdDSA')))->toThrow(SignatureInvalidException::class);
});

it('produces a credential that is rejected after it expires', function () {
    CarbonImmutable::setTestNow('2026-10-11 12:00:00');
    $credential = (new IssueFluxCredential)->issue($this->node, ['system.ping.v1'], 1, 1)['credential'];

    JWT::$timestamp = CarbonImmutable::parse('2026-10-11 12:15:01')->timestamp;

    expect(fn () => decodeFluxCredential($credential))->toThrow(ExpiredException::class);
});

it('refuses to sign when the signing configuration is incomplete', function (string $key) {
    config()->set("constants.flux.{$key}", '');

    expect(fn () => (new IssueFluxCredential)->issue($this->node, ['system.ping.v1'], 1, 1))
        ->toThrow(RuntimeException::class, 'Flux signing configuration is incomplete.');
})->with(['signing_private_key', 'signing_key_id', 'issuer']);
