<?php

use App\Actions\Database\StartMongodb;
use App\Models\StandaloneMongodb;

it('uses the generated MongoDB health check in the Compose configuration', function () {
    $source = remoteOutputSource('app/Actions/Database/StartMongodb.php');

    expect($source)
        ->toContain("'healthcheck' => \$this->database->healthCheckConfiguration(\$this->generate_health_check_command())")
        ->not->toContain("'healthcheck' => \$this->database->healthCheckConfiguration([\n                        'CMD',\n                        'echo',\n                        'ok'");
});

function generatedMongodbHealthCheckCommand(array $attributes): array
{
    $action = new StartMongodb;
    $action->database = new StandaloneMongodb;
    $action->database->setRawAttributes($attributes, true);
    $method = new ReflectionMethod(StartMongodb::class, 'generate_health_check_command');

    return $method->invoke($action);
}

it('generates a credential-free writable-primary MongoDB health check', function (string $image, bool $enableSsl, ?string $sslMode, array $expectedCommand) {
    $command = generatedMongodbHealthCheckCommand([
        'uuid' => 'mongodb-test-resource',
        'image' => $image,
        'enable_ssl' => $enableSsl,
        'ssl_mode' => $sslMode,
        'mongo_initdb_root_username' => 'root-user',
        'mongo_initdb_root_password' => 'root-password',
    ]);

    expect($command)->toBe($expectedCommand)
        ->and(implode(' ', $command))
        ->not->toContain('root-user')
        ->not->toContain('root-password')
        ->not->toContain('--tlsAllowInvalidHostnames')
        ->not->toContain('--tlsAllowInvalidCertificates');
})->with([
    'without TLS' => ['mongo:8', false, null, [
        'CMD', 'mongosh', '--quiet', '--host', 'mongodb-test-resource', '--eval',
        'quit(db.hello().isWritablePrimary === true ? 0 : 1)',
    ]],
    'TLS allow mode' => ['mongo:8', true, 'allow', [
        'CMD', 'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls', '--tlsCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.hello().isWritablePrimary === true ? 0 : 1)',
    ]],
    'TLS prefer mode' => ['mongo:8', true, 'prefer', [
        'CMD', 'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls', '--tlsCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.hello().isWritablePrimary === true ? 0 : 1)',
    ]],
    'TLS require mode' => ['mongo:8', true, 'require', [
        'CMD', 'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls', '--tlsCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.hello().isWritablePrimary === true ? 0 : 1)',
    ]],
    'TLS verify-full mode' => ['mongo:8', true, 'verify-full', [
        'CMD', 'mongosh', '--quiet', '--host', 'mongodb-test-resource',
        '--tls',
        '--tlsCAFile',
        '/etc/mongo/certs/ca.pem',
        '--tlsCertificateKeyFile',
        '/etc/mongo/certs/server.pem',
        '--eval',
        'quit(db.hello().isWritablePrimary === true ? 0 : 1)',
    ]],
    'MongoDB 4 without TLS' => ['mongo:4.4', false, null, [
        'CMD', 'mongo', '--quiet', '--host', 'mongodb-test-resource', '--eval',
        'quit(db.isMaster().ismaster === true ? 0 : 1)',
    ]],
    'MongoDB 4 TLS require mode' => ['mongo:4.4', true, 'require', [
        'CMD', 'mongo', '--quiet', '--host', 'mongodb-test-resource',
        '--ssl', '--sslCAFile', '/etc/mongo/certs/ca.pem', '--eval',
        'quit(db.isMaster().ismaster === true ? 0 : 1)',
    ]],
    'MongoDB 4 TLS verify-full mode' => ['mongo:4.4', true, 'verify-full', [
        'CMD', 'mongo', '--quiet', '--host', 'mongodb-test-resource',
        '--ssl',
        '--sslCAFile',
        '/etc/mongo/certs/ca.pem',
        '--sslPEMKeyFile',
        '/etc/mongo/certs/server.pem',
        '--eval',
        'quit(db.isMaster().ismaster === true ? 0 : 1)',
    ]],
]);
