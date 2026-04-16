<?php

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;

function makeServiceDatabaseWithServer(array $database = [], array $server = []): ServiceDatabase
{
    $serverModel = new Server(array_merge([
        'ip' => '10.0.0.8',
        'name' => 'test-server',
    ], $server));

    $serviceModel = new Service([
        'name' => 'test-service',
    ]);
    $serviceModel->setRelation('server', $serverModel);

    $serviceDatabase = new ServiceDatabase(array_merge([
        'public_port' => 5432,
    ], $database));
    $serviceDatabase->setRelation('service', $serviceModel);

    return $serviceDatabase;
}

test('service database public url prefers configured host', function () {
    $serviceDatabase = makeServiceDatabaseWithServer([
        'fqdn' => 'db.example.com',
        'public_port' => 5432,
    ]);

    expect($serviceDatabase->getPublicHost())->toBe('db.example.com')
        ->and($serviceDatabase->getServiceDatabaseUrl())->toBe('db.example.com:5432');
});

test('service database public url falls back to server ip when host is blank', function () {
    $serviceDatabase = makeServiceDatabaseWithServer([
        'fqdn' => null,
        'public_port' => 3306,
    ], [
        'ip' => '203.0.113.10',
    ]);

    expect($serviceDatabase->getPublicHost())->toBe('203.0.113.10')
        ->and($serviceDatabase->getServiceDatabaseUrl())->toBe('203.0.113.10:3306');
});

test('service database host normalization strips scheme casing and trailing slash', function () {
    $serviceDatabase = makeServiceDatabaseWithServer([
        'fqdn' => ' HTTPS://DB.Example.COM/ ',
        'public_port' => 27017,
    ]);

    expect(ServiceDatabase::normalizePublicHost(' HTTPS://DB.Example.COM/ '))->toBe('db.example.com')
        ->and($serviceDatabase->getPublicHost())->toBe('db.example.com')
        ->and($serviceDatabase->getServiceDatabaseUrl())->toBe('db.example.com:27017');
});

test('service database public url is null without a public port', function () {
    $serviceDatabase = makeServiceDatabaseWithServer([
        'fqdn' => 'db.example.com',
        'public_port' => null,
    ]);

    expect($serviceDatabase->getServiceDatabaseUrl())->toBeNull();
});
