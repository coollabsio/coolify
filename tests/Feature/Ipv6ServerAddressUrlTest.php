<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

describe('instance sentinel url', function () {
    it('brackets an IPv6 public address', function (string $ipv6) {
        expect(ServerSetting::instanceSentinelUrl(null, null, $ipv6))->toBe('http://[2a01:4f8::1]:8000');
    })->with([
        'raw' => '2a01:4f8::1',
        'bracketed' => '[2a01:4f8::1]',
    ]);

    it('keeps an IPv4 public address unchanged', function () {
        expect(ServerSetting::instanceSentinelUrl(null, '203.0.113.10', '2a01:4f8::1'))->toBe('http://203.0.113.10:8000');
    });
});

describe('sentinel url from the current request', function () {
    beforeEach(function () {
        InstanceSettings::forceCreate(['id' => 0, 'fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => null]);
    });

    function sentinelUrlForRequestHost(string $url): ?string
    {
        app()->instance('request', Request::create($url));

        $settings = new ServerSetting;
        $settings->setRelation('server', new Server(['ip' => '203.0.113.20']));

        return $settings->generateSentinelUrl(save: false);
    }

    it('ignores IPv6 loopback request hosts', function (string $url) {
        expect(sentinelUrlForRequestHost($url))->toBeNull();
    })->with([
        'loopback' => 'http://[::1]:8000/',
        'unspecified' => 'http://[::]:8000/',
        'long loopback' => 'http://[0:0:0:0:0:0:0:1]:8000/',
    ]);

    it('keeps a public IPv6 request host', function () {
        expect(sentinelUrlForRequestHost('http://[2a01:4f8::1]:8000/'))->toBe('http://[2a01:4f8::1]:8000');
    });

    it('still ignores IPv4 loopback and keeps public IPv4 hosts', function () {
        expect(sentinelUrlForRequestHost('http://127.0.0.1:8000/'))->toBeNull()
            ->and(sentinelUrlForRequestHost('http://203.0.113.10:8000/'))->toBe('http://203.0.113.10:8000');
    });
});

describe('public database urls', function () {
    function publicDatabaseUrl(string $modelClass, array $attributes, string $serverIp): ?string
    {
        if ($modelClass === StandaloneMongodb::class) {
            $attributes['mongo_initdb_root_password'] = encrypt($attributes['mongo_initdb_root_password']);
        }
        $database = new $modelClass;
        $database->forceFill(array_merge(['is_public' => true, 'public_port' => 15432], $attributes));
        $database->setRelation('destination', (object) ['server' => (object) ['getIp' => $serverIp]]);

        return $database->external_db_url;
    }

    $databases = [
        'postgresql' => [StandalonePostgresql::class, ['postgres_user' => 'user', 'postgres_password' => 'pass', 'postgres_db' => 'app'], 'postgres://user:pass@%s:15432/app'],
        'mysql' => [StandaloneMysql::class, ['mysql_user' => 'user', 'mysql_password' => 'pass', 'mysql_database' => 'app'], 'mysql://user:pass@%s:15432/app'],
        'mariadb' => [StandaloneMariadb::class, ['mariadb_user' => 'user', 'mariadb_password' => 'pass', 'mariadb_database' => 'app'], 'mysql://user:pass@%s:15432/app'],
        'mongodb' => [StandaloneMongodb::class, ['mongo_initdb_root_username' => 'user', 'mongo_initdb_root_password' => 'pass'], 'mongodb://user:pass@%s:15432/?directConnection=true'],
        'redis' => [StandaloneRedis::class, ['image' => 'redis:5.0'], 'redis://@%s:15432/0'],
        'keydb' => [StandaloneKeydb::class, ['keydb_password' => 'pass'], 'redis://:pass@%s:15432/0'],
        'dragonfly' => [StandaloneDragonfly::class, ['dragonfly_password' => 'pass'], 'redis://:pass@%s:15432/0'],
        'clickhouse' => [StandaloneClickhouse::class, ['clickhouse_admin_user' => 'user', 'clickhouse_admin_password' => 'pass', 'clickhouse_db' => 'app'], 'clickhouse://user:pass@%s:15432/app'],
    ];

    it('brackets an IPv6 server address', function (string $modelClass, array $attributes, string $expected) {
        expect(publicDatabaseUrl($modelClass, $attributes, '2a01:4f8::1'))->toBe(sprintf($expected, '[2a01:4f8::1]'))
            ->and(publicDatabaseUrl($modelClass, $attributes, '[2a01:4f8::1]'))->toBe(sprintf($expected, '[2a01:4f8::1]'));
    })->with($databases);

    it('keeps an IPv4 server address unchanged', function (string $modelClass, array $attributes, string $expected) {
        expect(publicDatabaseUrl($modelClass, $attributes, '203.0.113.10'))->toBe(sprintf($expected, '203.0.113.10'));
    })->with($databases);
});

describe('public service database url', function () {
    function serviceDatabaseUrl(string $serverIp): string
    {
        $server = new Server(['ip' => $serverIp]);
        $server->id = 5;
        $service = new Service;
        $service->setRelation('server', $server);
        $database = new ServiceDatabase;
        $database->public_port = 15432;
        $database->setRelation('service', $service);

        return $database->getServiceDatabaseUrl();
    }

    it('brackets an IPv6 server address', function (string $ipv6) {
        expect(serviceDatabaseUrl($ipv6))->toBe('[2a01:4f8::1]:15432');
    })->with([
        'raw' => '2a01:4f8::1',
        'bracketed' => '[2a01:4f8::1]',
    ]);

    it('keeps an IPv4 server address unchanged', function () {
        expect(serviceDatabaseUrl('203.0.113.10'))->toBe('203.0.113.10:15432');
    });
});
