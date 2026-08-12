<?php

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Service\StartService;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledJobManager;
use App\Models\ScheduledDatabaseBackup;

describe('deployment_queue helper', function () {
    test('uses the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect(deployment_queue())->toBe('high');
    });

    test('uses the deployments queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect(deployment_queue())->toBe('deployments');
    });
});

describe('crons_queue helper', function () {
    test('uses the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect(crons_queue())->toBe('high');
    });

    test('uses the crons queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect(crons_queue())->toBe('crons');
    });
});

describe('start action job routing', function () {
    test('routes to the deployments queue on cloud', function (string $actionClass) {
        config(['constants.coolify.self_hosted' => false]);

        expect($actionClass::makeJob()->queue)->toBe('deployments');
    })->with([
        StartDatabase::class,
        StartDatabaseProxy::class,
        StartService::class,
    ]);

    test('routes to the high queue on self-hosted', function (string $actionClass) {
        config(['constants.coolify.self_hosted' => true]);

        expect($actionClass::makeJob()->queue)->toBe('high');
    })->with([
        StartDatabase::class,
        StartDatabaseProxy::class,
        StartService::class,
    ]);
});

describe('scheduled job routing', function () {
    test('scheduled jobs use the crons queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect((new ScheduledJobManager)->queue)->toBe('crons');
        expect((new DatabaseBackupJob(new ScheduledDatabaseBackup))->queue)->toBe('crons');
    });

    test('scheduled jobs use the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect((new ScheduledJobManager)->queue)->toBe('high');
        expect((new DatabaseBackupJob(new ScheduledDatabaseBackup))->queue)->toBe('high');
    });
});
