<?php

use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

it('issues production host tokens with the default capability profile', function () {
    [$privateKeyPath, $publicKeyPath] = createFluxJwtKeypair();

    Config::set('flux.jwt_private_key_path', $privateKeyPath);

    $token = app(AgentTokenIssuer::class)->issue('100.64.0.10');
    $claims = JWT::decode($token, new Key(file_get_contents($publicKeyPath), 'ES256'));

    expect($claims->sub)->toBe('100.64.0.10')
        ->and($claims->aud)->toBe('coold')
        ->and((array) $claims->caps)->toBe(config('flux.host_capabilities'))
        ->and((array) $claims->caps)->not->toContain('host-agent:default')
        ->and($claims->exp)->toBeGreaterThan(time());
});

it('issues production server tokens with server identity claims', function () {
    [$privateKeyPath, $publicKeyPath] = createFluxJwtKeypair();

    Config::set('flux.jwt_private_key_path', $privateKeyPath);

    $server = new V5Server;
    $server->forceFill([
        'uuid' => 'server_prod_123',
        'id' => 123,
        'team_id' => 7,
        'cluster_id' => 'cluster-456',
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $token = app(AgentTokenIssuer::class)->issueForServer($server);
    $claims = JWT::decode($token, new Key(file_get_contents($publicKeyPath), 'ES256'));

    expect($claims->sub)->toBe('server_prod_123')
        ->and((array) $claims->caps)->toBe(config('flux.host_capabilities'))
        ->and($claims->team_id)->toBe('7')
        ->and($claims->cluster_id)->toBe('cluster-456')
        ->and($claims->server_id)->toBe('server_prod_123')
        ->and($claims->wireguard_management_ip)->toBe('100.64.0.10');
});

it('mints a host jwt signed by the configured flux private key', function () {
    [$privateKeyPath, $publicKeyPath] = createFluxJwtKeypair();

    Config::set('flux.jwt_private_key_path', $privateKeyPath);

    $exitCode = Artisan::call('flux:dev', [
        'host_id' => 'coold-dev',
        '--caps' => 'containers.list,ingress.apply',
        '--ttl' => '600',
    ]);

    expect($exitCode)->toBe(0);

    $token = trim(Artisan::output());
    $claims = JWT::decode($token, new Key(file_get_contents($publicKeyPath), 'ES256'));

    expect($claims->sub)->toBe('coold-dev')
        ->and($claims->aud)->toBe('coold')
        ->and($claims->caps)->toBe(['containers.list', 'ingress.apply'])
        ->and($claims->exp)->toBeGreaterThan(time());
});

it('mints a host jwt with the dev capability profile by default', function () {
    [$privateKeyPath, $publicKeyPath] = createFluxJwtKeypair();

    Config::set('flux.jwt_private_key_path', $privateKeyPath);

    $exitCode = Artisan::call('flux:dev', [
        'host_id' => 'coold-dev',
        '--ttl' => '600',
    ]);

    expect($exitCode)->toBe(0);

    $token = trim(Artisan::output());
    $claims = JWT::decode($token, new Key(file_get_contents($publicKeyPath), 'ES256'));

    expect($claims->caps)->toBe(['host-agent:dev']);
});

it('writes the host jwt to an output path with owner-only permissions', function () {
    [$privateKeyPath] = createFluxJwtKeypair();
    $outputPath = storage_path('framework/testing/host-jwt');

    Config::set('flux.jwt_private_key_path', $privateKeyPath);

    $exitCode = Artisan::call('flux:dev', [
        'host_id' => 'coold-dev',
        '--output' => $outputPath,
    ]);

    expect($exitCode)->toBe(0);

    expect($outputPath)->toBeFile()
        ->and(substr(sprintf('%o', fileperms($outputPath)), -4))->toBe('0600');
});

/**
 * @return array{0: string, 1: string}
 */
function createFluxJwtKeypair(): array
{
    $directory = storage_path('framework/testing/flux-keys-'.bin2hex(random_bytes(4)));
    mkdir($directory, 0777, true);

    $privateKeyPath = $directory.'/jwt.priv';
    $publicKeyPath = $directory.'/jwt.pub';

    exec('openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out '.escapeshellarg($privateKeyPath), $output, $exitCode);
    expect($exitCode)->toBe(0);

    exec('openssl pkey -in '.escapeshellarg($privateKeyPath).' -pubout -out '.escapeshellarg($publicKeyPath), $output, $exitCode);
    expect($exitCode)->toBe(0);

    return [$privateKeyPath, $publicKeyPath];
}
