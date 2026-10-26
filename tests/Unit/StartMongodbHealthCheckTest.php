<?php

use App\Actions\Database\StartMongodb;
use App\Models\StandaloneMongodb;

function generatedMongodbHealthCheckCommand(array $attributes): array
{
    $action = new StartMongodb;
    $action->database = new StandaloneMongodb;
    $action->database->setRawAttributes($attributes, true);
    $method = new ReflectionMethod(StartMongodb::class, 'generate_health_check_command');

    return $method->invoke($action);
}

/**
 * Runs the health check script with fake shells on PATH and returns the program and arguments that were called.
 *
 * @param  list<string>  $availableShells
 */
function runMongodbHealthCheck(array $healthCheck, array $availableShells): string
{
    $bin = sys_get_temp_dir().'/mongodb-healthcheck-'.bin2hex(random_bytes(4));
    mkdir($bin);
    foreach ($availableShells as $shell) {
        file_put_contents("$bin/$shell", "#!/bin/sh\necho $shell; for arg in \"\$@\"; do echo \"\$arg\"; done\n");
        chmod("$bin/$shell", 0755);
    }

    $output = shell_exec('PATH='.escapeshellarg($bin).' /bin/sh -c '.escapeshellarg($healthCheck[1]));

    array_map('unlink', glob("$bin/*"));
    rmdir($bin);

    return trim((string) $output);
}

it('checks liveness with ping using mongosh when the image has it and the legacy mongo shell otherwise', function (array $availableShells, bool $enableSsl, ?string $sslMode, array $expectedCall) {
    $healthCheck = generatedMongodbHealthCheckCommand([
        'uuid' => 'mongodb-test-resource',
        'image' => 'registry.example.com/mongo:any',
        'enable_ssl' => $enableSsl,
        'ssl_mode' => $sslMode,
        'mongo_initdb_root_username' => 'root-user',
        'mongo_initdb_root_password' => 'root-password',
    ]);

    expect($healthCheck[0])->toBe('CMD-SHELL')
        ->and($healthCheck[1])
        ->not->toContain('root-user')
        ->not->toContain('root-password')
        ->not->toContain('AllowInvalid')
        ->and(runMongodbHealthCheck($healthCheck, $availableShells))->toBe(implode("\n", $expectedCall));
})->with([
    'mongosh without TLS' => [['mongosh', 'mongo'], false, null, [
        'mongosh', '--quiet', '--host', 'mongodb-test-resource', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
    'mongosh with TLS require' => [['mongosh'], true, 'require', [
        'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls', '--tlsCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
    'mongosh with TLS verify-full' => [['mongosh'], true, 'verify-full', [
        'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls', '--tlsCAFile', '/etc/mongo/certs/ca.pem',
        '--tlsCertificateKeyFile', '/etc/mongo/certs/server.pem', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
    'legacy mongo without TLS' => [['mongo'], false, null, [
        'mongo', '--quiet', '--host', 'mongodb-test-resource', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
    'legacy mongo with TLS require' => [['mongo'], true, 'require', [
        'mongo', '--quiet', '--host', 'mongodb-test-resource',
        '--ssl', '--sslCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
    'legacy mongo with TLS verify-full' => [['mongo'], true, 'verify-full', [
        'mongo', '--quiet', '--host', 'mongodb-test-resource',
        '--ssl', '--sslCAFile', '/etc/mongo/certs/ca.pem',
        '--sslPEMKeyFile', '/etc/mongo/certs/server.pem', '--eval',
        'quit(db.adminCommand({ ping: 1 }).ok === 1 ? 0 : 1)',
    ]],
]);
