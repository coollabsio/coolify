<?php

it('reuses resolved environment credentials in database startup integrations', function (string $action, array $expected, array $unexpected) {
    $source = file_get_contents(__DIR__."/../../app/Actions/Database/{$action}.php");

    expect($source)->toContain(...$expected)
        ->not->toContain(...$unexpected);
})->with([
    'clickhouse' => [
        'StartClickhouse',
        ['$this->resolvedClickhouseUser', '$this->resolvedClickhousePassword'],
        ['$this->database->clickhouse_admin_user, \'--password\'', '$this->database->clickhouse_admin_password, \'--query\''],
    ],
    'dragonfly' => [
        'StartDragonfly',
        ['$this->resolvedRedisPassword'],
        ['$this->database->dragonfly_password, \'ping\'', 'requirepass {$this->database->dragonfly_password}'],
    ],
    'keydb' => [
        'StartKeydb',
        ['$this->resolvedRedisPassword'],
        ['$this->database->keydb_password, \'ping\'', 'requirepass {$this->database->keydb_password}'],
    ],
    'mongodb' => [
        'StartMongodb',
        ['$this->resolvedMongoDatabase', '$this->resolvedMongoUsername', '$this->resolvedMongoPassword'],
        ['json_encode($this->database->mongo_initdb_database', 'json_encode($this->database->mongo_initdb_root_username', 'json_encode($this->database->mongo_initdb_root_password'],
    ],
    'mysql' => [
        'StartMysql',
        ['$this->resolvedMysqlRootPassword'],
        ['-p{$this->database->mysql_root_password}'],
    ],
    'postgresql' => [
        'StartPostgresql',
        ['$this->resolvedPostgresUser', '$this->resolvedPostgresDatabase'],
        ['$this->database->postgres_user, \'-d\'', '$this->database->postgres_db, \'-c\''],
    ],
]);

it('runs database start commands without persisting them through remote process', function (string $action) {
    $source = file_get_contents(__DIR__."/../../app/Actions/Database/{$action}.php");

    expect($source)
        ->toContain('ExecutesDatabaseStartCommands')
        ->toContain('executeDatabaseStartCommands(')
        ->not->toContain('return remote_process(');
})->with([
    'StartClickhouse',
    'StartDragonfly',
    'StartKeydb',
    'StartMariadb',
    'StartMongodb',
    'StartMysql',
    'StartPostgresql',
    'StartRedis',
]);

it('queues database starts with identifiers instead of generated commands', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartDatabase.php');

    expect($source)
        ->toContain('DatabaseStartJob::dispatch(')
        ->not->toContain('StartPostgresql::run(')
        ->not->toContain('StartRedis::run(');
});
