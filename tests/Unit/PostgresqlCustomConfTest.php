<?php

/**
 * A commented out listen_addresses line must not count as an active setting,
 * otherwise PostgreSQL falls back to its localhost default.
 */

use App\Actions\Database\StartPostgresql;

test('commented out listen_addresses is not treated as an active setting', function () {
    expect(StartPostgresql::hasListenAddresses("#listen_addresses = 'localhost'"))->toBeFalse();
    expect(StartPostgresql::hasListenAddresses("# listen_addresses = 'localhost'"))->toBeFalse();
    expect(StartPostgresql::hasListenAddresses("\t#\tlisten_addresses = '*'"))->toBeFalse();
});

test('active listen_addresses is detected', function () {
    expect(StartPostgresql::hasListenAddresses("listen_addresses = '*'"))->toBeTrue();
    expect(StartPostgresql::hasListenAddresses("listen_addresses='*'"))->toBeTrue();
    expect(StartPostgresql::hasListenAddresses("  listen_addresses = '*'"))->toBeTrue();
    expect(StartPostgresql::hasListenAddresses("LISTEN_ADDRESSES = '*'"))->toBeTrue();
});

test('a conf that only comments out listen_addresses is detected as missing', function () {
    $conf = "max_connections = 200\n#listen_addresses = 'localhost'\nshared_buffers = 1GB\n";

    expect(StartPostgresql::hasListenAddresses($conf))->toBeFalse();
});

test('an active listen_addresses is detected next to a commented one', function () {
    $conf = "max_connections = 200\n#listen_addresses = 'localhost'\nlisten_addresses = '*'\n";

    expect(StartPostgresql::hasListenAddresses($conf))->toBeTrue();
});

test('windows line endings are handled', function () {
    expect(StartPostgresql::hasListenAddresses("max_connections = 200\r\nlisten_addresses = '*'\r\n"))->toBeTrue();
    expect(StartPostgresql::hasListenAddresses("max_connections = 200\r\n#listen_addresses = 'localhost'\r\n"))->toBeFalse();
});

test('listen_addresses inside another setting value does not count', function () {
    expect(StartPostgresql::hasListenAddresses("log_line_prefix = 'listen_addresses = '"))->toBeFalse();
});

test('empty and null confs are detected as missing', function () {
    expect(StartPostgresql::hasListenAddresses(''))->toBeFalse();
    expect(StartPostgresql::hasListenAddresses(null))->toBeFalse();
});
