<?php

use App\Models\StandaloneClickhouse;

test('clickhouse uses clickhouse_db field in internal connection string', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'testuser';
    $clickhouse->clickhouse_admin_password = 'testpass';
    $clickhouse->clickhouse_db = 'mydb';
    $clickhouse->uuid = 'test-uuid';

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)
        ->toContain('mydb')
        ->toContain('testuser')
        ->toContain('test-uuid');
});

test('clickhouse defaults to default database when clickhouse_db is null', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'testuser';
    $clickhouse->clickhouse_admin_password = 'testpass';
    $clickhouse->clickhouse_db = null;
    $clickhouse->uuid = 'test-uuid';

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)->toContain('/default');
});

test('clickhouse external url uses correct database', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'admin';
    $clickhouse->clickhouse_admin_password = 'secret';
    $clickhouse->clickhouse_db = 'production';
    $clickhouse->uuid = 'prod-uuid';
    $clickhouse->is_public = true;
    $clickhouse->public_port = 8123;

    $clickhouse->destination = new class
    {
        public $server;

        public function __construct()
        {
            $this->server = new class
            {
                public function __get($name)
                {
                    if ($name === 'getIp') {
                        return '1.2.3.4';
                    }
                }
            };
        }
    };
    $externalUrl = $clickhouse->external_db_url;

    expect($externalUrl)->toContain('production');

});
