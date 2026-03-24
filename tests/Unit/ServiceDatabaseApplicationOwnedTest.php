<?php

use App\Models\ServiceDatabase;

test('ServiceDatabase isApplicationOwned returns false when only service_id is set', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['service_id' => 1, 'application_id' => null]);
    $db->syncOriginal();

    expect($db->isApplicationOwned())->toBeFalse();
});

test('ServiceDatabase isApplicationOwned returns true when application_id is set', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['service_id' => null, 'application_id' => 5]);
    $db->syncOriginal();

    expect($db->isApplicationOwned())->toBeTrue();
});

test('ServiceDatabase databaseType works for application-owned postgres database', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['image' => 'postgres:15', 'application_id' => 5, 'service_id' => null, 'custom_type' => null]);
    $db->syncOriginal();

    expect($db->databaseType())->toBe('standalone-postgresql');
});

test('ServiceDatabase isBackupSolutionAvailable returns true for postgres image in application-owned db', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['image' => 'postgres:15', 'application_id' => 5, 'service_id' => null, 'custom_type' => null]);
    $db->syncOriginal();

    expect($db->isBackupSolutionAvailable())->toBeTrue();
});

test('ServiceDatabase isBackupSolutionAvailable returns true for mysql image in application-owned db', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['image' => 'mysql:8', 'application_id' => 5, 'service_id' => null, 'custom_type' => null]);
    $db->syncOriginal();

    expect($db->isBackupSolutionAvailable())->toBeTrue();
});

test('ServiceDatabase isBackupSolutionAvailable returns true for mariadb image in application-owned db', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['image' => 'mariadb:10', 'application_id' => 5, 'service_id' => null, 'custom_type' => null]);
    $db->syncOriginal();

    expect($db->isBackupSolutionAvailable())->toBeTrue();
});

test('ServiceDatabase isBackupSolutionAvailable returns false for non-database image in application-owned db', function () {
    $db = new ServiceDatabase;
    $db->setRawAttributes(['image' => 'nginx:alpine', 'application_id' => 5, 'service_id' => null, 'custom_type' => null]);
    $db->syncOriginal();

    expect($db->isBackupSolutionAvailable())->toBeFalse();
});
