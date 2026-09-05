<?php

use App\Livewire\Project\Database\Dragonfly\StatusInfo as DragonflyStatusInfo;
use App\Livewire\Project\Database\Keydb\StatusInfo as KeydbStatusInfo;
use App\Livewire\Project\Database\Mariadb\StatusInfo as MariadbStatusInfo;
use App\Livewire\Project\Database\Mongodb\StatusInfo as MongodbStatusInfo;
use App\Livewire\Project\Database\Mysql\StatusInfo as MysqlStatusInfo;
use App\Livewire\Project\Database\Postgresql\StatusInfo as PostgresqlStatusInfo;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;

/**
 * Guards coolify#10388: regenerating SSL certificates must produce the file
 * layout each database actually consumes. Only MongoDB reads a combined
 * server.pem; everything else loads separate server.crt + server.key.
 */
function resolveSslPemKeyFileRequired(string $componentClass): bool
{
    $component = new $componentClass;
    $method = new ReflectionMethod($componentClass, 'sslPemKeyFileRequired');

    return $method->invoke($component);
}

test('MongoDB regenerates SSL as a combined pem file', function () {
    expect(resolveSslPemKeyFileRequired(MongodbStatusInfo::class))->toBeTrue();
});

test('crt/key databases do not regenerate SSL as a pem file', function (string $componentClass) {
    expect(resolveSslPemKeyFileRequired($componentClass))->toBeFalse();
})->with([
    'Postgresql' => [PostgresqlStatusInfo::class],
    'MySQL' => [MysqlStatusInfo::class],
    'MariaDB' => [MariadbStatusInfo::class],
    'Redis' => [RedisStatusInfo::class],
    'KeyDB' => [KeydbStatusInfo::class],
    'Dragonfly' => [DragonflyStatusInfo::class],
]);
