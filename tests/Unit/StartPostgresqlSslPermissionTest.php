<?php

use App\Actions\Database\StartPostgresql;

test('prepareSslCertificatePermissions adds chown and chmod commands for ssl key and cert', function () {
    $action = new StartPostgresql;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $method = new ReflectionMethod(StartPostgresql::class, 'prepareSslCertificatePermissions');
    $method->setAccessible(true);
    $method->invoke($action);

    $sslDir = '/data/coolify/databases/test-uuid/ssl';
    $keyPath = escapeshellarg($sslDir.'/server.key');
    $crtPath = escapeshellarg($sslDir.'/server.crt');

    expect($action->commands)->toContain("chown 999:999 {$keyPath} {$crtPath}");
    expect($action->commands)->toContain("chmod 600 {$keyPath}");
    expect($action->commands)->toContain("chmod 644 {$crtPath}");
});

test('prepareSslCertificatePermissions places chown before chmod commands', function () {
    $action = new StartPostgresql;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $method = new ReflectionMethod(StartPostgresql::class, 'prepareSslCertificatePermissions');
    $method->setAccessible(true);
    $method->invoke($action);

    $sslDir = '/data/coolify/databases/test-uuid/ssl';
    $keyPath = escapeshellarg($sslDir.'/server.key');
    $crtPath = escapeshellarg($sslDir.'/server.crt');

    $chownIndex = array_search("chown 999:999 {$keyPath} {$crtPath}", $action->commands);
    $chmodKeyIndex = array_search("chmod 600 {$keyPath}", $action->commands);
    $chmodCrtIndex = array_search("chmod 644 {$crtPath}", $action->commands);

    expect($chownIndex)->toBeLessThan($chmodKeyIndex);
    expect($chownIndex)->toBeLessThan($chmodCrtIndex);
});

test('prepareSslCertificatePermissions escapes paths with special characters', function () {
    $action = new StartPostgresql;
    $action->configuration_dir = "/data/coolify/databases/uuid-with-'special";
    $action->commands = [];

    $method = new ReflectionMethod(StartPostgresql::class, 'prepareSslCertificatePermissions');
    $method->setAccessible(true);
    $method->invoke($action);

    $sslDir = "/data/coolify/databases/uuid-with-'special/ssl";
    $keyPath = escapeshellarg($sslDir.'/server.key');
    $crtPath = escapeshellarg($sslDir.'/server.crt');

    expect($action->commands)->toContain("chown 999:999 {$keyPath} {$crtPath}");
    expect($action->commands)->toContain("chmod 600 {$keyPath}");
    expect($action->commands)->toContain("chmod 644 {$crtPath}");
});
