<?php

use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array{0: string, 1: string}
 */
function createAgentTokenIssuerKeypair(): array
{
    $directory = storage_path('framework/testing/agent-token-keys-'.bin2hex(random_bytes(4)));
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

function decodeAgentToken(string $token, string $publicKeyPath, ?stdClass &$headers = null): stdClass
{
    // Firebase's JWT::decode only populates $headers when it is non-null on
    // entry (it opts out of clobbering by default), so seed a placeholder.
    if (func_num_args() >= 3) {
        $headers = new stdClass;
    }

    return JWT::decode($token, new Key(file_get_contents($publicKeyPath), 'ES256'), $headers);
}

beforeEach(function () {
    [$this->privateKeyPath, $this->publicKeyPath] = createAgentTokenIssuerKeypair();
    Config::set('flux.jwt_private_key_path', $this->privateKeyPath);
});

it('mints the explicit advertised capability list by default, not the wildcard profile', function () {
    $token = app(AgentTokenIssuer::class)->issue('100.64.0.10');
    $claims = decodeAgentToken($token, $this->publicKeyPath);

    expect((array) $claims->caps)->toBe(config('flux.host_capabilities'))
        ->and((array) $claims->caps)->toContain('containers.create')
        ->and((array) $claims->caps)->toContain('ingress.apply')
        ->and((array) $claims->caps)->toContain('host.jwt.set')
        ->and((array) $claims->caps)->not->toContain('host-agent:default')
        ->and((array) $claims->caps)->not->toContain('host-agent:dev')
        ->and((array) $claims->caps)->not->toContain('*');
});

it('mints the escape-hatch capability profile when configured', function () {
    Config::set('flux.host_capability_profile', 'host-agent:default');

    $token = app(AgentTokenIssuer::class)->issue('100.64.0.10');
    $claims = decodeAgentToken($token, $this->publicKeyPath);

    expect((array) $claims->caps)->toBe(['host-agent:default']);
});

it('includes a unique jti on every minted token', function () {
    $issuer = app(AgentTokenIssuer::class);

    $first = decodeAgentToken($issuer->issue('100.64.0.10'), $this->publicKeyPath);
    $second = decodeAgentToken($issuer->issue('100.64.0.10'), $this->publicKeyPath);

    expect($first->jti)->toBeString()->not->toBe('')
        ->and($second->jti)->toBeString()->not->toBe('')
        ->and($first->jti)->not->toBe($second->jti);
});

it('derives the token lifetime from config', function () {
    Config::set('flux.host_token_ttl', 1234);

    $before = time();
    $claims = decodeAgentToken(app(AgentTokenIssuer::class)->issue('100.64.0.10'), $this->publicKeyPath);

    expect($claims->exp - $claims->iat)->toBe(1234)
        ->and($claims->exp)->toBeGreaterThanOrEqual($before + 1234);
});

it('defaults host token lifetime to the flux max token lifetime', function () {
    $claims = decodeAgentToken(app(AgentTokenIssuer::class)->issue('100.64.0.10'), $this->publicKeyPath);

    expect($claims->exp - $claims->iat)->toBe(3600)
        ->and(config('flux.host_token_refresh_threshold'))->toBeLessThan(config('flux.host_token_ttl'));
});

it('clamps a below-floor ttl to 60 seconds', function () {
    $claims = decodeAgentToken(app(AgentTokenIssuer::class)->issue('100.64.0.10', null, 5), $this->publicKeyPath);

    expect($claims->exp - $claims->iat)->toBe(60);
});

it('sets the configured kid header for future key rotation', function () {
    Config::set('flux.jwt_kid', 'flux-2026');

    $headers = null;
    decodeAgentToken(app(AgentTokenIssuer::class)->issue('100.64.0.10'), $this->publicKeyPath, $headers);

    expect($headers->kid)->toBe('flux-2026')
        ->and($headers->alg)->toBe('ES256');
});

it('refuses to mint a host token before the server has a stable Flux host id', function () {
    $server = new V5Server([
        'node_address' => '203.0.113.10',
        'wireguard_management_ip' => '100.64.0.10',
    ]);

    expect(fn () => app(AgentTokenIssuer::class)->issueForServer($server))
        ->toThrow(RuntimeException::class, 'Server is missing a valid Flux host id.');
});

it('mints server host tokens for the stable server uuid, not the WireGuard management IP', function () {
    $server = new V5Server([
        'uuid' => 'server_abc123',
        'team_id' => 12,
        'cluster_id' => 34,
        'node_address' => '203.0.113.10',
        'wireguard_management_ip' => '100.64.0.10',
    ]);

    $claims = decodeAgentToken(app(AgentTokenIssuer::class)->issueForServer($server), $this->publicKeyPath);

    expect($claims->sub)->toBe('server_abc123')
        ->and($claims->server_id)->toBe('server_abc123')
        ->and($claims->wireguard_management_ip)->toBe('100.64.0.10');
});

it('warns when the private key file has insecure permissions', function () {
    chmod($this->privateKeyPath, 0644);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'insecure permissions')
            && $context['expected'] === '0600');

    app(AgentTokenIssuer::class)->issue('100.64.0.10');
});
