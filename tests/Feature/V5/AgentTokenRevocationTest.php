<?php

use App\Actions\V5\Server\RemoveBootstrapMarker;
use App\Models\V5\RevokedAgentToken;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

/**
 * @return array{0: string, 1: string}
 */
function createRevocationJwtKeypair(): array
{
    $directory = storage_path('framework/testing/revocation-keys-'.bin2hex(random_bytes(4)));
    mkdir($directory, 0777, true);

    $privateKeyPath = $directory.'/jwt.priv';
    $publicKeyPath = $directory.'/jwt.pub';

    exec('openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out '.escapeshellarg($privateKeyPath), $out, $code);
    expect($code)->toBe(0);
    chmod($privateKeyPath, 0600);

    exec('openssl pkey -in '.escapeshellarg($privateKeyPath).' -pubout -out '.escapeshellarg($publicKeyPath), $out, $code);
    expect($code)->toBe(0);

    return [$privateKeyPath, $publicKeyPath];
}

function createRevocationServer(): V5Server
{
    return V5Server::query()->create([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);
}

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_revoked_agent_tokens');
    Schema::dropIfExists('v5_servers');
    V5TestSchema::createServersTable();
    V5TestSchema::createRevokedAgentTokensTable();

    [$this->privateKeyPath, $this->publicKeyPath] = createRevocationJwtKeypair();
    Config::set('flux.jwt_private_key_path', $this->privateKeyPath);
});

afterEach(function () {
    Schema::dropIfExists('v5_revoked_agent_tokens');
    Schema::dropIfExists('v5_servers');
});

it('persists the issued jti on the server and reports it as not revoked', function () {
    $server = createRevocationServer();

    $token = app(AgentTokenIssuer::class)->issueForServer($server);
    $claims = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'));

    expect($server->fresh()->agent_token_jti)->toBe($claims->jti)
        ->and(app(AgentTokenIssuer::class)->isRevoked($claims->jti))->toBeFalse();
});

it('revokes the currently-issued token and clears the server jti', function () {
    $issuer = app(AgentTokenIssuer::class);
    $server = createRevocationServer();

    $token = $issuer->issueForServer($server);
    $jti = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'))->jti;

    $issuer->revoke($server->fresh());

    expect($issuer->isRevoked($jti))->toBeTrue()
        ->and(RevokedAgentToken::query()->where('jti', $jti)->where('server_id', $server->id)->exists())->toBeTrue()
        ->and($server->fresh()->agent_token_jti)->toBeNull();
});

it('is idempotent when revoking a server with no issued token', function () {
    $server = createRevocationServer();

    app(AgentTokenIssuer::class)->revoke($server);

    expect(RevokedAgentToken::query()->count())->toBe(0);
});

it('pushes the revocation to flux with the jti and expires_at', function () {
    $issuer = app(AgentTokenIssuer::class);
    $server = createRevocationServer();

    $token = $issuer->issueForServer($server);
    $server = $server->fresh();
    $jti = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'))->jti;
    $expiresAtUnix = $server->agent_token_expires_at->getTimestamp();

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('revokeToken')->once()->with($jti, $expiresAtUnix);
    app()->instance(FluxClient::class, $flux);

    $issuer->revoke($server);

    expect($issuer->isRevoked($jti))->toBeTrue()
        ->and(RevokedAgentToken::query()->where('jti', $jti)->exists())->toBeTrue();
});

it('does not throw and keeps the local revocation record when the flux push fails', function () {
    $issuer = app(AgentTokenIssuer::class);
    $server = createRevocationServer();

    $token = $issuer->issueForServer($server);
    $jti = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'))->jti;

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('revokeToken')->once()->andThrow(new RuntimeException('flux socket unreachable'));
    app()->instance(FluxClient::class, $flux);

    // Flux is down: revoke must not throw, and the local record still stands.
    $issuer->revoke($server->fresh());

    expect($issuer->isRevoked($jti))->toBeTrue()
        ->and(RevokedAgentToken::query()->where('jti', $jti)->exists())->toBeTrue()
        ->and($server->fresh()->agent_token_jti)->toBeNull();
});

it('revokeForServer revokes the token and pushes to flux like revoke', function () {
    $issuer = app(AgentTokenIssuer::class);
    $server = createRevocationServer();

    $token = $issuer->issueForServer($server);
    $jti = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'))->jti;

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('revokeToken')->once()->with($jti, Mockery::type('int'));
    app()->instance(FluxClient::class, $flux);

    $issuer->revokeForServer($server->fresh());

    expect($issuer->isRevoked($jti))->toBeTrue()
        ->and($server->fresh()->agent_token_jti)->toBeNull();
});

it('revokes the host token when the bootstrap marker is removed', function () {
    $issuer = app(AgentTokenIssuer::class);
    $server = createRevocationServer();

    $token = $issuer->issueForServer($server);
    $jti = JWT::decode($token, new Key(file_get_contents($this->publicKeyPath), 'ES256'))->jti;

    // The server has no private key, so the SSH cleanup returns false, but the
    // revocation (a pure DB write) still runs first.
    $result = RemoveBootstrapMarker::run($server->fresh());

    expect($result)->toBeFalse()
        ->and($issuer->isRevoked($jti))->toBeTrue();
});
